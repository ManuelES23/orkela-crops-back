<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfEmployeeFaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfEmployeeControllerTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    public function test_destroy_revokes_active_face_template(): void
    {
        Storage::fake('local');
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $template = SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employee->id,
            'embedding' => array_fill(0, 128, 0.1),
            'photo_path' => 'private/sf-face-templates/x.jpg',
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);

        $this->deleteJson("/api/splendidfarms/administration/personal/empleados/{$employee->id}")
            ->assertStatus(200);

        $template->refresh();
        $this->assertSame('revoked', $template->status);
        $this->assertNotNull($template->revoked_at);
    }

    public function test_destroy_does_not_fail_without_face_template(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);

        $this->deleteJson("/api/splendidfarms/administration/personal/empleados/{$employee->id}")
            ->assertStatus(200);
    }
}
