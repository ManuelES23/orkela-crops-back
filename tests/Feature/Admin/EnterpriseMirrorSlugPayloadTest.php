<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseMirrorSlugPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_enterprise_index_exposes_mirror_source_slug(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $root = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);
        Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);

        $response = $this->getJson('/api/enterprises')->assertStatus(200);

        $mirror = collect($response->json('data'))->firstWhere('slug', 'finca-modelo-demo');
        $this->assertSame('splendidfarms', $mirror['mirror_source_slug']);

        $rootRow = collect($response->json('data'))->firstWhere('slug', 'splendidfarms');
        $this->assertNull($rootRow['mirror_source_slug']);
    }

    public function test_hierarchical_permissions_exposes_mirror_source_slug(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $root = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        UserEnterpriseAccess::create([
            'user_id' => $user->id, 'enterprise_id' => $mirror->id,
            'is_active' => true, 'granted_at' => now(),
        ]);

        $response = $this->getJson("/api/users/{$user->id}/hierarchical-permissions")->assertStatus(200);

        $row = collect($response->json('data.enterprises'))->firstWhere('slug', 'finca-modelo-demo');
        $this->assertSame('splendidfarms', $row['mirror_source_slug']);
    }
}
