<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\ActivityLog;
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

    public function test_enroll_does_not_leak_embedding_into_activity_log(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $log = ActivityLog::where('model', 'SfEmployeeFaceTemplate')->where('action', 'create')->firstOrFail();

        $this->assertArrayNotHasKey('embedding', $log->new_values ?? []);
        $this->assertNull($log->old_values);
        // El resto de los atributos sí se deben seguir logueando con normalidad.
        $this->assertArrayHasKey('photo_path', $log->new_values);
        $this->assertArrayHasKey('status', $log->new_values);
    }

    public function test_reenroll_does_not_leak_old_or_new_embedding_into_activity_log(): void
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

        $updateLog = ActivityLog::where('model', 'SfEmployeeFaceTemplate')->where('action', 'update')->firstOrFail();

        $this->assertArrayNotHasKey('embedding', $updateLog->new_values ?? []);
        $this->assertArrayNotHasKey('embedding', $updateLog->old_values ?? []);
        // El resto de los campos que sí cambiaron (p. ej. photo_path) se deben
        // seguir logueando con normalidad.
        $this->assertArrayHasKey('photo_path', $updateLog->new_values);
    }

    public function test_reenroll_without_new_consent_document_preserves_existing_path(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face1.jpg', 640, 480),
            'consent_signed' => '1',
            'consent_document' => UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $original = SfEmployeeFaceTemplate::where('sf_employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($original->consent_document_path);
        Storage::disk('local')->assertExists($original->consent_document_path);
        $originalConsentPath = $original->consent_document_path;

        // Re-enrolamiento SIN volver a adjuntar el documento de consentimiento
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face2.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $template = SfEmployeeFaceTemplate::where('sf_employee_id', $employee->id)->firstOrFail();
        $this->assertSame($originalConsentPath, $template->consent_document_path);
        // El archivo original del consentimiento sigue en disco, no huérfano.
        Storage::disk('local')->assertExists($originalConsentPath);
    }

    public function test_reenroll_with_new_consent_document_replaces_path_and_deletes_old_file(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id);

        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face1.jpg', 640, 480),
            'consent_signed' => '1',
            'consent_document' => UploadedFile::fake()->create('consent1.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $original = SfEmployeeFaceTemplate::where('sf_employee_id', $employee->id)->firstOrFail();
        $originalConsentPath = $original->consent_document_path;
        $this->assertNotNull($originalConsentPath);

        // Re-enrolamiento adjuntando un documento de consentimiento nuevo
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face2.jpg', 640, 480),
            'consent_signed' => '1',
            'consent_document' => UploadedFile::fake()->create('consent2.pdf', 100, 'application/pdf'),
        ])->assertStatus(201);

        $template = SfEmployeeFaceTemplate::where('sf_employee_id', $employee->id)->firstOrFail();
        $this->assertNotSame($originalConsentPath, $template->consent_document_path);
        Storage::disk('local')->assertExists($template->consent_document_path);
        Storage::disk('local')->assertMissing($originalConsentPath);
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

    public function test_photo_returns_binary_for_same_tenant(): void
    {
        Storage::fake('local');
        $this->fakeNodeService();
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $this->postJson($this->enrollUrl($employee->id), [
            'photo' => UploadedFile::fake()->image('face.jpg', 640, 480),
            'consent_signed' => '1',
        ])->assertStatus(201);

        $response = $this->get("/api/splendidfarms/administration/personal/empleados/{$employee->id}/face-template/photo");

        $response->assertStatus(200);
    }

    public function test_photo_returns_404_without_active_template(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $this->get("/api/splendidfarms/administration/personal/empleados/{$employee->id}/face-template/photo")
            ->assertStatus(404);
    }
}
