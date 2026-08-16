<?php

namespace Tests\Concerns;

use App\Models\Enterprise;
use App\Models\SfEmployee;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures mínimos para probar el módulo de Personal SF (empleados +
 * plantillas biométricas). No reutiliza CreatesAssetFixtures::setUpAssetFixtures()
 * porque ese helper crea Branch/EntityType/Entity/AssetCategory/Brand/UnitOfMeasure
 * irrelevantes para este módulo; sigue el mismo patrón de creación de
 * Enterprise/User autenticado documentado en ese trait.
 */
trait CreatesSfPersonalFixtures
{
    /**
     * Crea una Enterprise + User autenticado (Sanctum::actingAs) y los retorna
     * como [$user, $enterprise].
     */
    protected function createAuthenticatedUserWithEnterprise(array $enterpriseOverrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        $user = User::factory()->create();

        $enterprise = Enterprise::create(array_merge([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms-' . $sequence,
            'description' => 'Empresa agrícola de prueba',
            'is_active' => true,
        ], $enterpriseOverrides));

        // Sin esta fila en el pivot user_enterprises, User::hasEnterpriseAccess()/
        // activeEnterprises() (usado por el guard de autorización de los
        // endpoints de Plan 2) nunca vería a este usuario como miembro de la
        // empresa que él mismo acaba de crear, y todos los tests "felices"
        // recibirían 403 en vez del código de estado que en realidad prueban.
        $user->enterprises()->attach($enterprise->id, [
            'role' => 'admin',
            'is_active' => true,
            'granted_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return [$user, $enterprise];
    }

    protected function createSfEmployee(int $enterpriseId, array $overrides = []): SfEmployee
    {
        static $sequence = 0;
        $sequence++;

        return SfEmployee::create(array_merge([
            'enterprise_id' => $enterpriseId,
            'code' => 'SFE-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'first_name' => 'Empleado',
            'last_name' => 'Prueba' . $sequence,
            'hire_date' => now()->subYear()->toDateString(),
            'status' => 'active',
        ], $overrides));
    }
}
