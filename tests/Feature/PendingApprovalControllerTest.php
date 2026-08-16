<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class PendingApprovalControllerTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    public function test_summary_includes_field_check_review_entry_for_user_with_permission(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['status' => 'active']);
        $check = $this->makeCheckDirectly(null, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'no_template',
        ]);

        $this->grantFieldCheckReviewPermission($user, $enterprise);

        $response = $this->getJson('/api/pending-approvals/summary');

        $response->assertStatus(200);
        $entry = collect($response->json('data.processes'))->firstWhere('code', 'field_check_review');
        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual(1, $entry['pending_count']);
    }

    public function test_summary_excludes_field_check_review_entry_without_permission(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $this->makeCheckDirectly(null, $user->id, [
            'enterprise_id' => $enterprise->id,
            'verification_status' => 'no_template',
        ]);

        $response = $this->getJson('/api/pending-approvals/summary');

        $response->assertStatus(200);
        $entry = collect($response->json('data.processes'))->firstWhere('code', 'field_check_review');
        $this->assertNull($entry);
    }

    /**
     * Inserta directamente en user_submodule_access (sin filas en
     * user_submodule_permissions) replicando el patrón real leído en
     * hasTransferApprovalPermission: sin tipos de permiso definidos para el
     * submódulo, el carve-out de producción trata el acceso como permitido.
     */
    private function grantFieldCheckReviewPermission($user, $enterprise): void
    {
        $application = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $enterprise->id],
            ['name' => 'Administración', 'description' => 'Administración', 'icon' => 'Settings', 'path' => '/administration', 'order' => 1, 'is_active' => true]
        );
        $module = Module::firstOrCreate(
            ['slug' => 'personal', 'application_id' => $application->id],
            ['name' => 'Personal', 'description' => 'Personal', 'icon' => 'Users', 'path' => '/personal', 'order' => 1, 'is_active' => true]
        );
        $submodule = Submodule::firstOrCreate(
            ['slug' => 'revision-asistencia', 'module_id' => $module->id],
            ['name' => 'Revisión de Asistencia', 'description' => 'Revisión de Asistencia', 'icon' => 'ShieldCheck', 'path' => '/revision-asistencia', 'order' => 1, 'is_active' => true]
        );

        DB::table('user_submodule_access')->insert([
            'user_id' => $user->id,
            'submodule_id' => $submodule->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Copiado de SfFieldCheckControllerTest::makeCheckDirectly() — no vive en
     * el trait compartido CreatesSfPersonalFixtures, así que se replica aquí
     * con la misma firma para no depender de otro archivo de test.
     */
    private function makeCheckDirectly(?int $employeeId, int $checkerId, array $overrides = []): \App\Models\SfFieldCheck
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
