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
}
