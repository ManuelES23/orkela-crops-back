<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseProvisionSuiteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        return $admin;
    }

    public function test_provision_suite_builds_tree_and_is_idempotent(): void
    {
        $this->actingAsAdmin();
        $root = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);

        $first = $this->postJson("/api/enterprises/{$mirror->id}/provision-suite")->assertStatus(200);
        $this->assertGreaterThan(0, $first->json('data.created.applications'));

        $second = $this->postJson("/api/enterprises/{$mirror->id}/provision-suite")->assertStatus(200);
        $this->assertSame(0, $second->json('data.created.applications'));
    }

    public function test_provision_suite_rejects_enterprise_without_mirror_source(): void
    {
        $this->actingAsAdmin();
        $independent = Enterprise::create(['name' => 'Independiente', 'slug' => 'independiente', 'description' => 'x', 'is_active' => true]);

        $this->postJson("/api/enterprises/{$independent->id}/provision-suite")
            ->assertStatus(422);
    }

    public function test_store_rejects_mirror_source_that_is_not_a_root(): void
    {
        $this->actingAsAdmin();
        $root = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);
        $notRoot = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);

        $this->postJson('/api/enterprises', [
            'name' => 'Otra Demo', 'description' => 'x', 'mirror_source_id' => $notRoot->id,
        ])->assertStatus(422);
    }

    public function test_update_rejects_changing_mirror_source_once_provisioned(): void
    {
        $this->actingAsAdmin();
        $rootA = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);
        $rootB = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $rootA->id,
        ]);

        $this->postJson("/api/enterprises/{$mirror->id}/provision-suite")->assertStatus(200);

        $this->putJson("/api/enterprises/{$mirror->id}", [
            'name' => 'Finca Modelo', 'description' => 'x', 'mirror_source_id' => $rootB->id,
        ])->assertStatus(422);
    }
}
