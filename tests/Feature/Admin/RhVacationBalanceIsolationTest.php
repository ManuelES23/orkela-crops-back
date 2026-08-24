<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\VacationBalance;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BootsDynamicEnterpriseRoutes;
use Tests\TestCase;

/**
 * Regresión para el hallazgo Crítico de seguridad sobre
 * VacationController::initializeBalances() (y recalculateBalance()):
 * el endpoint no tenía ningún filtro por enterprise_id y, al estar las rutas
 * de RH registradas dinámicamente para cada empresa espejo de
 * 'grupoesplendido', cualquier usuario autenticado de CUALQUIER empresa
 * espejo podía reinicializar (sobrescribir) los balances de vacaciones de
 * los empleados reales de Grupo Espléndido, y la respuesta filtraba el
 * roster completo (id + nombre) de esos empleados.
 */
class RhVacationBalanceIsolationTest extends TestCase
{
    use RefreshDatabase;
    use BootsDynamicEnterpriseRoutes;

    private function makeEmployee(Enterprise $enterprise, string $employeeNumber): Employee
    {
        return Employee::create([
            'enterprise_id' => $enterprise->id,
            'employee_number' => $employeeNumber,
            'first_name' => 'Empleado',
            'last_name' => $employeeNumber,
            'hire_date' => now()->subYears(3),
            'qr_code' => 'qr-' . $employeeNumber . '-' . uniqid(),
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    public function test_user_from_mirror_cannot_initialize_balances_for_another_enterprise(): void
    {
        $root = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Agroverde', 'slug' => 'agroverde-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);
        $this->refreshApplication();

        $rootEmployee = $this->makeEmployee($root, 'ROOT-001');

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        // El usuario solo tiene acceso a la empresa espejo, pero intenta
        // inicializar balances de la empresa raíz (Grupo Espléndido).
        $this->postJson('/api/agroverde-demo/rh/vacaciones/inicializar-balances', [
            'enterprise_id' => $root->id,
        ])
            ->assertStatus(403)
            ->assertJson(['status' => 'error']);

        // No debe haberse escrito ningún balance para la empresa raíz.
        $this->assertSame(0, VacationBalance::where('employee_id', $rootEmployee->id)->count());
    }

    public function test_initializing_balances_only_affects_the_authorized_enterprise(): void
    {
        $root = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Agroverde', 'slug' => 'agroverde-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);
        $this->refreshApplication();

        $rootEmployee = $this->makeEmployee($root, 'ROOT-002');
        $mirrorEmployee = $this->makeEmployee($mirror, 'MIRROR-002');

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/agroverde-demo/rh/vacaciones/inicializar-balances', [
            'enterprise_id' => $mirror->id,
        ])->assertStatus(200);

        $response->assertJsonPath('data.processed', 1);
        $response->assertJsonMissingPath('data.details');

        // Solo el empleado de la empresa espejo (autorizada) recibe balance.
        $this->assertSame(1, VacationBalance::where('employee_id', $mirrorEmployee->id)->count());
        $this->assertSame(0, VacationBalance::where('employee_id', $rootEmployee->id)->count());
    }

    public function test_user_from_mirror_cannot_recalculate_balance_of_employee_in_another_enterprise(): void
    {
        $root = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Agroverde', 'slug' => 'agroverde-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);
        $this->refreshApplication();

        $rootEmployee = $this->makeEmployee($root, 'ROOT-003');

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        $this->postJson("/api/agroverde-demo/rh/vacaciones/empleado/{$rootEmployee->id}/recalcular", [])
            ->assertStatus(403)
            ->assertJson(['status' => 'error']);

        $this->assertSame(0, VacationBalance::where('employee_id', $rootEmployee->id)->count());
    }
}
