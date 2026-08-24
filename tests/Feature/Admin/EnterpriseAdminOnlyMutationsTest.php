<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regresión para el hallazgo Crítico del review final de rama: store(),
 * update() y destroy() de EnterpriseController no tenían ningún guard de
 * rol admin (solo provisionSuite() lo tenía). Como mirror_source_id ahora
 * controla si se registra un bloque entero de rutas (~575 líneas) bajo un
 * prefijo nuevo en cada boot de la app, cualquier usuario autenticado no
 * admin podía crear una empresa con un mirror_source_id arbitrario y
 * forzar el registro de un nuevo grupo de rutas — sin límite, sin filtro
 * de is_active.
 */
class EnterpriseAdminOnlyMutationsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsNonAdmin(): User
    {
        $user = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_non_admin_cannot_create_enterprise(): void
    {
        $this->actingAsNonAdmin();

        $this->postJson('/api/enterprises', [
            'name' => 'Empresa Intrusa',
            'description' => 'x',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('enterprises', ['name' => 'Empresa Intrusa']);
    }

    public function test_non_admin_cannot_update_enterprise(): void
    {
        $this->actingAsNonAdmin();
        $enterprise = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);

        $this->putJson("/api/enterprises/{$enterprise->id}", [
            'name' => 'Splendid Farms Hackeada',
            'description' => 'x',
        ])->assertStatus(403);

        $this->assertSame('Splendid Farms', $enterprise->fresh()->name);
    }

    public function test_non_admin_cannot_delete_enterprise(): void
    {
        $this->actingAsNonAdmin();
        $enterprise = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);

        $this->deleteJson("/api/enterprises/{$enterprise->id}")->assertStatus(403);

        $this->assertDatabaseHas('enterprises', ['id' => $enterprise->id]);
    }

    public function test_admin_can_still_create_update_and_delete_enterprise(): void
    {
        $this->actingAsAdmin();

        $create = $this->postJson('/api/enterprises', [
            'name' => 'Empresa Admin',
            'description' => 'x',
        ])->assertStatus(201);

        $id = $create->json('data.id');

        $this->putJson("/api/enterprises/{$id}", [
            'name' => 'Empresa Admin Editada',
            'description' => 'x',
        ])->assertStatus(200);

        $this->assertDatabaseHas('enterprises', ['id' => $id, 'name' => 'Empresa Admin Editada']);

        $this->deleteJson("/api/enterprises/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('enterprises', ['id' => $id]);
    }
}
