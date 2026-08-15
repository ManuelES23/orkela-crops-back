<?php
// sentinel-back/tests/Feature/Jobs/VerifyFieldCheckJobTest.php
namespace Tests\Feature\Jobs;

use App\Jobs\VerifyFieldCheckJob;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class VerifyFieldCheckJobTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private function fakeEmbedResponse(array $embedding): void
    {
        Http::fake([
            '*/embed' => Http::response(['embedding' => $embedding, 'model_version' => 'faceapi-v1'], 200),
        ]);
    }

    private function makeCheck(int $employeeId, int $checkerId, array $overrides = []): SfFieldCheck
    {
        Storage::fake('local');
        $path = 'private/sf-field-checks-evidence/' . uniqid() . '.jpg';
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        return SfFieldCheck::create(array_merge([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employeeId,
            'checked_by_user_id' => $checkerId,
            'type' => SfFieldCheck::TYPE_CHECK_IN,
            'checked_at' => now(),
            'synced_at' => now(),
            'evidence_photo_path' => $path,
            'client_confidence' => 0.1,
            'verification_status' => SfFieldCheck::STATUS_PENDING,
            'manual_override' => false,
            'clock_skew_seconds' => 5,
        ], $overrides));
    }

    public function test_verified_match_consolidates_into_attendance(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding); // distancia 0 -> match

        $check = $this->makeCheck($employee->id, $user->id, ['checked_at' => now()]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_VERIFIED, $check->verification_status);
        $this->assertEqualsWithDelta(0.0, (float) $check->server_confidence, 0.0001);

        $record = SfAttendanceRecord::where('sf_employee_id', $employee->id)
            ->where('date', $check->checked_at->toDateString())
            ->first();
        $this->assertNotNull($record);
        $this->assertSame('field_biometric', $record->source_device);
        $this->assertNotNull($record->check_in);
    }

    public function test_mismatch_does_not_consolidate(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.0),
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        // Embedding muy distinto -> distancia grande -> mismatch
        $this->fakeEmbedResponse(array_fill(0, 128, 5.0));

        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_MISMATCH, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_no_template_status_when_employee_not_enrolled(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheck($employee->id, $user->id);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_NO_TEMPLATE, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_no_employee_id_stays_no_template(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $check = $this->makeCheck($enterprise->id, $user->id, ['sf_employee_id' => null]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_NO_TEMPLATE, $check->verification_status);
    }

    public function test_manual_override_never_auto_verifies(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding); // match perfecto, pero manual_override obliga a revisión

        $check = $this->makeCheck($employee->id, $user->id, ['manual_override' => true]);

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_clock_skew_beyond_tolerance_forces_review(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding);

        config(['biometrics.clock_skew_tolerance_minutes' => 10]);
        $check = $this->makeCheck($employee->id, $user->id, ['clock_skew_seconds' => 3600]); // 1 hora

        (new VerifyFieldCheckJob($check->id))->handle();

        $check->refresh();
        $this->assertSame(SfFieldCheck::STATUS_LOW_CONFIDENCE, $check->verification_status);
        $this->assertDatabaseCount('sf_attendance_records', 0);
    }

    public function test_check_out_after_check_in_computes_hours_worked(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $embedding = array_fill(0, 128, 0.2);
        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => $embedding,
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
        $this->fakeEmbedResponse($embedding);

        $morning = now()->setTime(8, 0);
        $checkIn = $this->makeCheck($employee->id, $user->id, ['type' => SfFieldCheck::TYPE_CHECK_IN, 'checked_at' => $morning]);
        (new VerifyFieldCheckJob($checkIn->id))->handle();

        $evening = now()->setTime(17, 0);
        $checkOut = $this->makeCheck($employee->id, $user->id, ['type' => SfFieldCheck::TYPE_CHECK_OUT, 'checked_at' => $evening]);
        (new VerifyFieldCheckJob($checkOut->id))->handle();

        $record = SfAttendanceRecord::where('sf_employee_id', $employee->id)
            ->where('date', $morning->toDateString())
            ->firstOrFail();

        $this->assertEqualsWithDelta(9.0, (float) $record->hours_worked, 0.01);
    }
}
