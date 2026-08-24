<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EnterpriseMirrorScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mirrors_of_returns_root_and_its_mirrors(): void
    {
        $root = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Raíz agrícola',
            'is_active' => true,
        ]);

        $mirror = Enterprise::create([
            'name' => 'Finca Modelo',
            'slug' => 'finca-modelo-demo',
            'description' => 'Espejo agrícola',
            'is_active' => true,
            'mirror_source_id' => $root->id,
        ]);

        $unrelated = Enterprise::create([
            'name' => 'Grupo Espléndido',
            'slug' => 'grupoesplendido',
            'description' => 'Raíz RH, no debe aparecer',
            'is_active' => true,
        ]);

        $slugs = Enterprise::mirrorsOf('splendidfarms')->pluck('slug')->all();

        $this->assertEqualsCanonicalizing(['splendidfarms', 'finca-modelo-demo'], $slugs);
        $this->assertSame('splendidfarms', $mirror->mirrorSource->slug);
    }

    public function test_mirrors_of_respects_active_filter_after_scope(): void
    {
        // Root enterprise for the OTHER source
        $otherRoot = Enterprise::create([
            'name' => 'Other Root',
            'slug' => 'otherroot',
            'description' => 'A different root',
            'is_active' => true,
        ]);

        // Mirror of the other root (active)
        $otherMirror = Enterprise::create([
            'name' => 'Other Mirror',
            'slug' => 'other-mirror',
            'description' => 'Mirror of other root',
            'is_active' => true,
            'mirror_source_id' => $otherRoot->id,
        ]);

        // Root enterprise with matching slug that is INACTIVE
        // This should NOT be returned when filtering by is_active = true
        $inactiveRoot = Enterprise::create([
            'name' => 'Inactive Root',
            'slug' => 'targetslug',
            'description' => 'An inactive root that matches our search slug',
            'is_active' => false,
        ]);

        // Get all enterprises matching the scope WITHOUT the active filter first
        // to show that the scope does find the inactive root
        $allResults = Enterprise::mirrorsOf('targetslug')->pluck('slug')->all();
        $this->assertContains('targetslug', $allResults,
            'Scope should find the root enterprise by slug, regardless of active status');

        // Query: get all enterprises that are mirrors of 'targetslug', filtered to active only
        // Expected: should return NOTHING (no active mirrors or root)
        // Bug: With unfixed code, the precedence of OR/AND causes:
        //   WHERE slug = 'targetslug' OR EXISTS(...) AND is_active = 1
        //   Evaluated as: slug = 'targetslug' OR (EXISTS(...) AND is_active = 1)
        //   So inactive root with matching slug would be returned!
        $filteredResults = Enterprise::mirrorsOf('targetslug')
            ->where('is_active', true)
            ->pluck('slug')
            ->all();

        // Should NOT include the inactive root 'targetslug' when filtered by is_active = true
        $this->assertNotContains('targetslug', $filteredResults,
            'Inactive root with matching slug should not be returned when filtered by is_active = true (PRECEDENCE BUG TEST)');

        // Should be empty since there are no active mirrors of 'targetslug'
        $this->assertEmpty($filteredResults,
            'No active enterprises should match the targetslug scope');
    }
}
