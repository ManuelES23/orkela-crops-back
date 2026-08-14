<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfEmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfFaceTemplateControllerTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private function fakeNodeService(array $embedding = null): void
    {
        Http::fake([
            '*/embed' => Http::response([
                'embedding' => $embedding ?? array_fill(0, 128, 0.25),
                'model_version' => 'faceapi-v1',
            ], 200),
        ]);
    }

    private function enrollUrl(int $employeeId): string
    {
        return "/api/splendidfarms/administration/personal/empleados/{$employeeId}/face-template";
    }

    public function test_requires_authentication(): void
    {
        $this->postJson($this->enrollUrl(1))->assertStatus(401);
    }

    public function test_enroll_requires_consent(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $response = $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            // consent_signed ausente
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('sf_employee_face_templates', 0);
    }

    public function test_enroll_creates_template_with_canonical_embedding(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $response = $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $template = SfEmployeeFaceTemplate::firstOrFail();
        $this->assertSame($employee->id, $template->sf_employee_id);
        $this->assertCount(128, $template->embedding);
        $this->assertSame('faceapi-v1', $template->model_version);
        $this->assertSame('active', $template->status);
        $this->assertNotNull($template->consent_signed_at);
        Storage::disk('local')->assertExists($template->photo_path);
        $this->assertStringStartsWith('private/sf-face-templates/', $template->photo_path);
    }

    public function test_reenroll_replaces_existing_template(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face1.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face2.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        // Sigue habiendo una sola plantilla para el empleado (updateOrCreate)
        $this->assertSame(1, SfEmployeeFaceTemplate::where('sf_employee_id', $employee->id)->count());
    }

    public function test_enroll_returns_422_when_no_face_detected(): void
    {
        Storage::fake('local');
        Http::fake(['*/embed' => Http::response(['error' => 'no_face'], 422)]);
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $response = $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('landscape.jpg', 640, 480),
            'consent_signed' => '1',
        ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertDatabaseCount('sf_employee_face_templates', 0);
    }

    public function test_revoke_marks_template_revoked(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $this->deleteJson($this->enrollUrl($employee->id))
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $template = SfEmployeeFaceTemplate::firstOrFail();
        $this->assertSame('revoked', $template->status);
        $this->assertNotNull($template->revoked_at);
        $this->assertNull($employee->fresh()->faceTemplate);
    }

    public function test_employee_index_includes_has_face_template(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $enrolled = $this->createSfEmployee($enterprise->id);
        $notEnrolled = $this->createSfEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($enrolled->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $response = $this->getJson('/api/splendidfarms/administration/personal/empleados?enterprise_id=' . $enterprise->id);
        $response->assertStatus(200);

        $rows = collect($response->json('data.data') ?? $response->json('data'));
        $this->assertTrue((bool) $rows->firstWhere('id', $enrolled->id)['has_face_template']);
        $this->assertFalse((bool) $rows->firstWhere('id', $notEnrolled->id)['has_face_template']);
    }
}
