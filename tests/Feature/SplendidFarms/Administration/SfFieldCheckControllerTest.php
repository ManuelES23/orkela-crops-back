<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfEmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfFieldCheckControllerTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    /**
     * Requiere que el test que la llama haya invocado Storage::fake('local') PRIMERO —
     * fake() reinicia el disco cada vez que se llama, así que llamarlo aquí adentro
     * borraría el archivo de un enrollEmployee() anterior en el mismo test.
     */
    private function enrollEmployee(int $employeeId, array $embeddingOverride = null): SfEmployeeFaceTemplate
    {
        $photo = UploadedFile::fake()->image('face.jpg', 100, 100)->get();
        $path = "private/sf-face-templates/{$employeeId}.jpg";
        Storage::disk('local')->put($path, $photo);

        return SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employeeId,
            'embedding' => $embeddingOverride ?? array_fill(0, 128, 0.1),
            'photo_path' => $path,
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
    }

    public function test_crew_package_requires_authentication(): void
    {
        $this->getJson('/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id=1')
            ->assertStatus(401);
    }

    public function test_crew_package_returns_only_enrolled_active_employees(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $enrolled = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($enrolled->id);
        $notEnrolled = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $inactive = $this->createSfEmployee($enterprise->id, ['status' => 'inactive']);
        $this->enrollEmployee($inactive->id);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $ids = collect($response->json('data.employees'))->pluck('id');
        $this->assertTrue($ids->contains($enrolled->id));
        $this->assertFalse($ids->contains($notEnrolled->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_crew_package_includes_embedding_and_thumbnail(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id, array_fill(0, 128, 0.25));

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}");

        $row = collect($response->json('data.employees'))->firstWhere('id', $employee->id);
        $this->assertCount(128, $row['embedding']);
        $this->assertEqualsWithDelta(0.25, $row['embedding'][0], 0.0001);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $row['thumbnail']);
        $this->assertSame('faceapi-v1', $response->json('data.model_version'));
    }

    public function test_crew_package_excludes_other_enterprises(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [$otherUser, $otherEnterprise] = $this->createAuthenticatedUserWithEnterprise();
        $otherEmployee = $this->createSfEmployee($otherEnterprise->id, ['status' => 'active']);
        $this->enrollEmployee($otherEmployee->id);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}");

        $ids = collect($response->json('data.employees'))->pluck('id');
        $this->assertFalse($ids->contains($otherEmployee->id));
    }

    public function test_sync_requires_authentication(): void
    {
        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [])
            ->assertStatus(401);
    }

    private function fakeEmbedResponse(): void
    {
        Http::fake([
            '*/embed' => Http::response([
                'embedding' => array_fill(0, 128, 0.1),
                'model_version' => 'faceapi-v1',
            ], 200),
        ]);
    }

    private function tinyJpegBase64(): string
    {
        // JPEG 1x1 válido, suficiente para pasar por el pipeline de storage (el matching real se prueba en Task 4)
        return 'data:image/jpeg;base64,' . base64_encode(base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='
        ));
    }

    public function test_sync_accepts_new_check_and_dispatches_verification(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.12,
                'manual_override' => false,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $this->assertDatabaseHas('sf_field_checks', ['client_uuid' => $uuid, 'sf_employee_id' => $employee->id]);
        Queue::assertPushed(\App\Jobs\VerifyFieldCheckJob::class);
    }

    public function test_sync_is_idempotent_on_repeated_client_uuid(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $payload = [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ];
        $uuid = $payload['checks'][0]['client_uuid'];

        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', $payload)->assertStatus(200);
        $second = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', $payload);

        $this->assertSame('duplicate', collect($second->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $this->assertSame(1, \App\Models\SfFieldCheck::where('client_uuid', $uuid)->count());
    }

    public function test_sync_accepts_check_without_employee_match(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => null,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);
        $check = \App\Models\SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertNull($check->sf_employee_id);
        $this->assertSame(\App\Models\SfFieldCheck::STATUS_PENDING, $check->verification_status);
    }

    public function test_sync_computes_clock_skew(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->enrollEmployee($employee->id);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $skewedTime = now()->subMinutes(45)->toIso8601String();

        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $employee->id,
                'type' => 'check_in',
                'checked_at' => $skewedTime,
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ])->assertStatus(200);

        $check = \App\Models\SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertGreaterThanOrEqual(2600, $check->clock_skew_seconds); // ~45 min en segundos, con margen
    }

    public function test_sync_rejects_employee_from_a_different_enterprise(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [$otherUser, $otherEnterprise] = $this->createAuthenticatedUserWithEnterprise();
        $otherEmployee = $this->createSfEmployee($otherEnterprise->id, ['status' => 'active']);

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $response = $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [[
                'client_uuid' => $uuid,
                'sf_employee_id' => $otherEmployee->id,
                'type' => 'check_in',
                'checked_at' => now()->toIso8601String(),
                'evidence_photo' => $this->tinyJpegBase64(),
                'client_confidence' => 0.1,
            ]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sf_field_checks', ['client_uuid' => $uuid]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/splendidfarms/administration/personal/field-checks?enterprise_id=1')
            ->assertStatus(401);
    }

    public function test_index_filters_by_verification_status(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $verified = $this->makeCheckDirectly($employee->id, $user->id, ['verification_status' => 'verified']);
        $mismatch = $this->makeCheckDirectly($employee->id, $user->id, ['verification_status' => 'mismatch']);

        $response = $this->getJson("/api/splendidfarms/administration/personal/field-checks?enterprise_id={$enterprise->id}&verification_status=mismatch");

        $response->assertStatus(200);
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($mismatch->id));
        $this->assertFalse($ids->contains($verified->id));
    }

    private function makeCheckDirectly(int $employeeId, int $checkerId, array $overrides = []): \App\Models\SfFieldCheck
    {
        return \App\Models\SfFieldCheck::create(array_merge([
            'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'sf_employee_id' => $employeeId,
            'checked_by_user_id' => $checkerId,
            'type' => 'check_in',
            'checked_at' => now(),
            'evidence_photo_path' => 'private/sf-field-checks-evidence/fake.jpg',
            'verification_status' => 'pending',
            'manual_override' => false,
        ], $overrides));
    }
}
