# Aprovisionamiento Visual de Empresas (Espejo de Suite) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que un administrador cree una empresa nueva, la marque como "espejo" de una de las 3 suites existentes (Agrícola/RH/Comercio) y la deje funcionando de punta a punta — rutas, vistas, datos aislados — desde el panel web, sin tocar código ni redeployar.

**Architecture:** Columna `mirror_source_id` en `enterprises` (FK a sí misma) determina de qué empresa raíz una empresa es espejo. Las rutas de las 3 suites pasan de arrays/prefijos fijos a un `foreach` sobre `Enterprise::mirrorsOf($rootSlug)`. Un `EnterpriseProvisioningService` nuevo reutiliza las funciones ya existentes de `BuildsEnterpriseStructure` para construir el árbol de Aplicación/Módulo/Submódulo de la empresa nueva. El frontend deja de tener un mapa de mirrors hardcodeado (`DEMO_ENTERPRISE_MIRRORS`) y lo lee del backend en runtime.

**Tech Stack:** Laravel 12 (PHP 8.2), Sanctum, MySQL/SQLite (tests), React 19 + Vite 7, TailwindCSS 4.

**Spec:** [docs/superpowers/specs/2026-08-24-visual-enterprise-provisioning-design.md](../specs/2026-08-24-visual-enterprise-provisioning-design.md)

## Global Constraints

- **Nunca correr `php artisan test`/`migrate` con `--env=...`** — ver `CLAUDE.md` del backend, incidente real de pérdida de datos. Siempre `php artisan test` a secas o con `--filter=`.
- Después de cada cambio de código PHP: `php artisan route:clear && php artisan config:clear && php artisan view:clear` + `php -l <archivo>.php`.
- Después de cada cambio de código frontend: `npm run build` debe compilar sin errores; `npm run lint` sin errores nuevos.
- No cadenas de espejos: `mirror_source_id` solo puede apuntar a una empresa donde `mirror_source_id IS NULL`.
- No se permite cambiar `mirror_source_id` de una empresa que ya tiene Aplicaciones creadas (evita mezclar suites).
- El contenido interior de los bloques de rutas existentes (`Route::prefix('administration')->group(...)`, etc.) no se reindenta al envolverlos en el `foreach` — diff mínimo, mismo criterio que el retrofit de multi-tenancy anterior.

---

## Task 1: Migración `mirror_source_id` + relación/scope en `Enterprise`

**Files:**
- Create: `database/migrations/2026_08_24_100000_add_mirror_source_id_to_enterprises_table.php`
- Modify: `app/Models/Enterprise.php`
- Test: `tests/Feature/Admin/EnterpriseMirrorScopeTest.php`

**Interfaces:**
- Produces: `Enterprise::scopeMirrorsOf(Builder $query, string $rootSlug): Builder` — usado por Task 4 (rutas) y Task 2 (servicio de aprovisionamiento).
- Produces: `Enterprise::mirrorSource(): BelongsTo` — relación a la empresa raíz.
- Produces: columna `enterprises.mirror_source_id` (nullable, FK a `enterprises.id`, `nullOnDelete`).

- [ ] **Step 1: Escribir el test que falla**

```php
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
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=EnterpriseMirrorScopeTest`
Expected: FAIL — columna `mirror_source_id` no existe / método `mirrorsOf`/`mirrorSource` no existen.

- [ ] **Step 3: Crear la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->foreignId('mirror_source_id')
                ->nullable()
                ->after('id')
                ->constrained('enterprises')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mirror_source_id');
        });
    }
};
```

- [ ] **Step 4: Agregar la relación y el scope al modelo**

En `app/Models/Enterprise.php`, agregar `'mirror_source_id'` a `$fillable` (después de `'slug'`), y agregar:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

```php
/**
 * Empresa raíz de la que esta empresa es "espejo" (mismas rutas, mismas
 * vistas, misma estructura de Aplicación/Módulo/Submódulo). Null si esta
 * empresa es ella misma una raíz con su propia suite.
 */
public function mirrorSource(): BelongsTo
{
    return $this->belongsTo(Enterprise::class, 'mirror_source_id');
}

/**
 * Devuelve la empresa raíz identificada por $rootSlug junto con todas sus
 * empresas espejo. Usado tanto por las rutas dinámicas (routes/api.php)
 * como por el aprovisionamiento de estructura.
 */
public function scopeMirrorsOf(Builder $query, string $rootSlug): Builder
{
    return $query->where('slug', $rootSlug)
        ->orWhereHas('mirrorSource', fn (Builder $q) => $q->where('slug', $rootSlug));
}
```

- [ ] **Step 5: Migrar y correr el test**

Run: `php artisan migrate`
Run: `php artisan test --filter=EnterpriseMirrorScopeTest`
Expected: PASS

- [ ] **Step 6: Lint y limpiar cachés**

Run: `php -l app/Models/Enterprise.php`
Run: `php artisan route:clear && php artisan config:clear && php artisan view:clear`

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_24_100000_add_mirror_source_id_to_enterprises_table.php app/Models/Enterprise.php tests/Feature/Admin/EnterpriseMirrorScopeTest.php
git commit -m "feat: columna mirror_source_id + scope mirrorsOf en Enterprise"
```

---

## Task 2: `EnterpriseProvisioningService` — extraer la lógica de construcción de árbol

**Files:**
- Create: `app/Services/EnterpriseProvisioningService.php`
- Modify: `database/seeders/Concerns/BuildsEnterpriseStructure.php`
- Test: `tests/Feature/Admin/EnterpriseProvisioningServiceTest.php`

**Interfaces:**
- Consumes: `Enterprise::mirrorSource()` (Task 1).
- Produces: `EnterpriseProvisioningService::provision(Enterprise $enterprise): array` — devuelve `['application' => 'RH', 'created' => ['applications' => int, 'modules' => int, 'submodules' => int]]` para el resumen que consume el endpoint de Task 3.
- Produces: métodos públicos reutilizables `provisionAgricultural(Enterprise $enterprise): array`, `provisionCorporateRh(Enterprise $enterprise): array`, `provisionTrade(Enterprise $enterprise): array` — consumidos también por `BuildsEnterpriseStructure` (los Seeders).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_builds_agricultural_tree_for_a_mirror(): void
    {
        $root = Enterprise::create([
            'name' => 'Splendid Farms', 'slug' => 'splendidfarms',
            'description' => 'Raíz', 'is_active' => true,
        ]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo',
            'description' => 'Espejo', 'is_active' => true,
            'mirror_source_id' => $root->id,
        ]);

        app(EnterpriseProvisioningService::class)->provision($mirror);

        $this->assertTrue(
            Application::where('enterprise_id', $mirror->id)->where('slug', 'operacion-agricola')->exists()
        );
        $agricola = Module::whereHas('application', fn ($q) => $q->where('enterprise_id', $mirror->id))
            ->where('slug', 'agricola')->first();
        $this->assertNotNull($agricola);
        $this->assertTrue(
            Submodule::where('module_id', $agricola->id)->where('slug', 'temporadas')->exists()
        );
    }

    public function test_provision_is_idempotent(): void
    {
        $root = Enterprise::create([
            'name' => 'Splendid Farms', 'slug' => 'splendidfarms',
            'description' => 'Raíz', 'is_active' => true,
        ]);
        $mirror = Enterprise::create([
            'name' => 'Finca Modelo', 'slug' => 'finca-modelo-demo',
            'description' => 'Espejo', 'is_active' => true,
            'mirror_source_id' => $root->id,
        ]);

        $service = app(EnterpriseProvisioningService::class);
        $service->provision($mirror);
        $countBefore = Application::where('enterprise_id', $mirror->id)->count();

        $service->provision($mirror);
        $countAfter = Application::where('enterprise_id', $mirror->id)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    public function test_provision_throws_when_no_mirror_source(): void
    {
        $independent = Enterprise::create([
            'name' => 'Independiente', 'slug' => 'independiente',
            'description' => 'Sin suite', 'is_active' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(EnterpriseProvisioningService::class)->provision($independent);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=EnterpriseProvisioningServiceTest`
Expected: FAIL — `App\Services\EnterpriseProvisioningService` no existe.

- [ ] **Step 3: Crear el servicio**

Crear `app/Services/EnterpriseProvisioningService.php`. El cuerpo de cada método `provisionX` es **exactamente igual** al de los métodos actuales en
`database/seeders/Concerns/BuildsEnterpriseStructure.php`:

- `provisionAgricultural()` = cuerpo de `buildAgriculturalSuite()` (líneas 127-508 del archivo), quitando las 17 llamadas a `$this->command->info(...)`.
- `provisionCorporateRh()` = cuerpo de `buildCorporateRhSuite()` (líneas 27-114), quitando las 9 llamadas a `$this->command->info(...)`.
- `provisionTrade()` = cuerpo de `buildTradeSuite()` (líneas 514-743), quitando las 14 llamadas a `$this->command->info(...)`.
- `ensureSubmodulePermissionTypes()` (líneas 745-777) se copia sin cambios — no usa `$this->command`.

Cada método devuelve un resumen de lo creado en vez de solo hacer side-effects, contando antes/después:

```php
<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;

/**
 * Construye la jerarquía Aplicación → Módulo → Submódulo para los 3
 * perfiles de negocio del sistema (Agrícola, RH, Comercio). Extraído de
 * database/seeders/Concerns/BuildsEnterpriseStructure.php para poder
 * invocarse también desde un controller HTTP (el trait original depende de
 * $this->command, solo disponible en un Seeder ejecutado por Artisan).
 *
 * BuildsEnterpriseStructure delega en esta clase — el árbol vive en un solo
 * lugar, el trait solo le agrega logging de consola encima.
 */
class EnterpriseProvisioningService
{
    /**
     * Aprovisiona la suite correspondiente según la empresa raíz de la que
     * $enterprise es espejo (Enterprise::mirrorSource()).
     *
     * @return array{application: string, created: array{applications: int, modules: int, submodules: int}}
     */
    public function provision(Enterprise $enterprise): array
    {
        $root = $enterprise->mirrorSource;

        if (! $root) {
            throw new \InvalidArgumentException(
                'La empresa no tiene una suite asignada (mirror_source_id vacío).'
            );
        }

        return match ($root->slug) {
            'splendidfarms' => $this->provisionAgricultural($enterprise),
            'grupoesplendido' => $this->provisionCorporateRh($enterprise),
            'splendidbyporvenir' => $this->provisionTrade($enterprise),
            default => throw new \InvalidArgumentException("Suite raíz desconocida: {$root->slug}"),
        };
    }

    /**
     * Perfil "Corporativo": una sola aplicación de Recursos Humanos.
     * Cuerpo idéntico a BuildsEnterpriseStructure::buildCorporateRhSuite(),
     * sin las llamadas a $this->command->info().
     */
    public function provisionCorporateRh(Enterprise $enterprise): array
    {
        [$appsBefore, $modsBefore, $subsBefore] = $this->countTree($enterprise);

        // ... cuerpo idéntico a buildCorporateRhSuite() (líneas 27-114 del
        // trait), sin las líneas $this->command->info(...) ...

        return $this->summarize('Recursos Humanos', $enterprise, $appsBefore, $modsBefore, $subsBefore);
    }

    /**
     * Perfil "Agrícola completo": Administración + Inventario + Contabilidad
     * + Operación Agrícola. Cuerpo idéntico a
     * BuildsEnterpriseStructure::buildAgriculturalSuite() (líneas 127-508),
     * sin las llamadas a $this->command->info().
     */
    public function provisionAgricultural(Enterprise $enterprise): array
    {
        [$appsBefore, $modsBefore, $subsBefore] = $this->countTree($enterprise);

        // ... cuerpo idéntico a buildAgriculturalSuite() ...

        return $this->summarize('Suite Agrícola', $enterprise, $appsBefore, $modsBefore, $subsBefore);
    }

    /**
     * Perfil "Comercio/Exportación": Administración + Inventario + Ventas +
     * Exportaciones + Compras de Fruta. Cuerpo idéntico a
     * BuildsEnterpriseStructure::buildTradeSuite() (líneas 514-743), sin las
     * llamadas a $this->command->info().
     */
    public function provisionTrade(Enterprise $enterprise): array
    {
        [$appsBefore, $modsBefore, $subsBefore] = $this->countTree($enterprise);

        // ... cuerpo idéntico a buildTradeSuite() ...

        return $this->summarize('Comercio', $enterprise, $appsBefore, $modsBefore, $subsBefore);
    }

    protected function ensureSubmodulePermissionTypes(Module $module, string $submoduleSlug, array $types): void
    {
        $submodule = Submodule::where('module_id', $module->id)
            ->where('slug', $submoduleSlug)
            ->first();

        if (! $submodule) {
            return;
        }

        $order = (int) (SubmodulePermissionType::where('submodule_id', $submodule->id)->max('order') ?? 0);

        foreach ($types as $type) {
            $exists = SubmodulePermissionType::where('submodule_id', $submodule->id)
                ->where('slug', $type['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            $order++;

            SubmodulePermissionType::create([
                'submodule_id' => $submodule->id,
                'slug' => $type['slug'],
                'name' => $type['name'],
                'description' => $type['description'],
                'order' => $order,
                'is_active' => true,
            ]);
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function countTree(Enterprise $enterprise): array
    {
        $appIds = Application::where('enterprise_id', $enterprise->id)->pluck('id');
        $modIds = Module::whereIn('application_id', $appIds)->pluck('id');
        $subCount = Submodule::whereIn('module_id', $modIds)->count();

        return [$appIds->count(), $modIds->count(), $subCount];
    }

    private function summarize(string $label, Enterprise $enterprise, int $appsBefore, int $modsBefore, int $subsBefore): array
    {
        [$appsAfter, $modsAfter, $subsAfter] = $this->countTree($enterprise);

        return [
            'application' => $label,
            'created' => [
                'applications' => $appsAfter - $appsBefore,
                'modules' => $modsAfter - $modsBefore,
                'submodules' => $subsAfter - $subsBefore,
            ],
        ];
    }
}
```

- [ ] **Step 4: Hacer que el trait delegue en el servicio**

En `database/seeders/Concerns/BuildsEnterpriseStructure.php`, reemplazar el cuerpo de los 3 métodos por una llamada al servicio, conservando el logging de consola:

```php
protected function buildCorporateRhSuite(Enterprise $enterprise): void
{
    $this->command->info('');
    $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");
    app(\App\Services\EnterpriseProvisioningService::class)->provisionCorporateRh($enterprise);
    $this->command->info('    → Recursos Humanos: Catálogos, Empleados, Asistencia, Gestión');
}

protected function buildAgriculturalSuite(Enterprise $enterprise): void
{
    $this->command->info('');
    $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");
    app(\App\Services\EnterpriseProvisioningService::class)->provisionAgricultural($enterprise);
    $this->command->info('    → Administración, Inventario, Contabilidad, Operación Agrícola creados/verificados');
}

protected function buildTradeSuite(Enterprise $enterprise): void
{
    $this->command->info('');
    $this->command->info("📱 Creando aplicaciones para: {$enterprise->name}");
    app(\App\Services\EnterpriseProvisioningService::class)->provisionTrade($enterprise);
    $this->command->info('    → Administración, Inventario, Ventas, Exportaciones, Compras de Fruta creados/verificados');
}
```

`ensureSubmodulePermissionTypes()` y las demás funciones del trait (`grantFullAccess()`) se quedan tal cual — solo se movió la construcción del árbol.

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --filter=EnterpriseProvisioningServiceTest`
Expected: PASS (los 3 tests)

- [ ] **Step 6: Verificar que los seeders existentes siguen funcionando igual**

Run: `php artisan test --filter=Seeder` (si no hay tests de seeder, correr manualmente contra una BD de prueba local: `php artisan migrate:fresh --seed` en un entorno que NO sea la BD real de desarrollo — nunca contra `orkela_crops`)

- [ ] **Step 7: Lint y commit**

```bash
php -l app/Services/EnterpriseProvisioningService.php
php -l database/seeders/Concerns/BuildsEnterpriseStructure.php
git add app/Services/EnterpriseProvisioningService.php database/seeders/Concerns/BuildsEnterpriseStructure.php tests/Feature/Admin/EnterpriseProvisioningServiceTest.php
git commit -m "refactor: extraer construcción de árbol de suite a EnterpriseProvisioningService"
```

---

## Task 3: Endpoint `provision-suite` + validaciones en `EnterpriseController`

**Files:**
- Modify: `app/Http/Controllers/Api/EnterpriseController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Admin/EnterpriseProvisionSuiteEndpointTest.php`

**Interfaces:**
- Consumes: `EnterpriseProvisioningService::provision()` (Task 2), `Enterprise::mirrorSource()` (Task 1).
- Produces: `POST /api/enterprises/{id}/provision-suite` → `{status, message, data: {application, created: {applications, modules, submodules}}}`.
- Produces: validación en `store()`/`update()` que rechaza `mirror_source_id` apuntando a una empresa que no es raíz, y rechaza cambiarlo si la empresa ya tiene Aplicaciones.

- [ ] **Step 1: Escribir el test que falla**

```php
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

        $this->postJson("/api/enterprises/{$mirror->id}", [
            'name' => 'Finca Modelo', 'description' => 'x', 'mirror_source_id' => $rootB->id,
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=EnterpriseProvisionSuiteEndpointTest`
Expected: FAIL — ruta `provision-suite` no existe (404), validaciones de `mirror_source_id` no existen.

- [ ] **Step 3: Agregar validación reusable en `EnterpriseController`**

Agregar un método privado reusado por `store()` y `update()`:

```php
use App\Services\EnterpriseProvisioningService;

/**
 * Regla de validación para mirror_source_id: si viene seteado, debe
 * apuntar a una empresa raíz (mirror_source_id IS NULL en ella misma) —
 * evita cadenas de espejos.
 */
private function mirrorSourceRule(): \Closure
{
    return function ($attribute, $value, $fail) {
        if (! $value) {
            return;
        }

        $target = Enterprise::find($value);
        if (! $target) {
            $fail('La empresa a espejar no existe.');
            return;
        }

        if ($target->mirror_source_id) {
            $fail('La empresa seleccionada como espejo ya es, a su vez, un espejo de otra. Elegí una empresa raíz.');
        }
    };
}
```

En `store()`, agregar al array de validación:

```php
'mirror_source_id' => ['nullable', 'integer', 'exists:enterprises,id', $this->mirrorSourceRule()],
```

En `update()`, agregar la misma regla más el chequeo de inmutabilidad post-aprovisionamiento, antes de `$enterprise->update($validated)`:

```php
'mirror_source_id' => ['nullable', 'integer', 'exists:enterprises,id', $this->mirrorSourceRule()],
```

```php
if (array_key_exists('mirror_source_id', $validated)
    && (int) ($validated['mirror_source_id'] ?? 0) !== (int) ($enterprise->mirror_source_id ?? 0)
    && $enterprise->applications()->exists()
) {
    return response()->json([
        'status' => 'error',
        'message' => 'No se puede cambiar la suite de una empresa que ya fue aprovisionada. Eliminá la empresa y creála de nuevo si necesitás otra suite.',
    ], 422);
}
```

- [ ] **Step 4: Agregar el método `provisionSuite`**

```php
/**
 * Aprovisiona (o re-sincroniza) el árbol de Aplicación/Módulo/Submódulo
 * de una empresa espejo, según la suite de su mirror_source_id.
 */
public function provisionSuite(Request $request, $id): JsonResponse
{
    if (! $request->user() || $request->user()->role !== 'admin') {
        return response()->json(['status' => 'error', 'message' => 'No autorizado.'], 403);
    }

    $enterprise = is_numeric($id) ? Enterprise::find($id) : Enterprise::where('slug', $id)->first();

    if (! $enterprise) {
        return response()->json(['status' => 'error', 'message' => 'Empresa no encontrada'], 404);
    }

    try {
        $summary = app(EnterpriseProvisioningService::class)->provision($enterprise);
    } catch (\InvalidArgumentException $e) {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Suite aprovisionada correctamente.',
        'data' => $summary,
    ]);
}
```

- [ ] **Step 5: Registrar la ruta**

En `routes/api.php`, junto a las otras rutas de `enterprises/{enterprise}/...` (línea ~84):

```php
Route::post('enterprises/{enterprise}/provision-suite', [App\Http\Controllers\Api\EnterpriseController::class, 'provisionSuite']);
```

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `php artisan test --filter=EnterpriseProvisionSuiteEndpointTest`
Expected: PASS (los 4 tests)

- [ ] **Step 7: Regresión — correr toda la suite**

Run: `php artisan test`
Expected: todos los tests pasan, incluidos los 176 del retrofit anterior.

- [ ] **Step 8: Lint, limpiar cachés y commit**

```bash
php -l app/Http/Controllers/Api/EnterpriseController.php
php artisan route:clear && php artisan config:clear && php artisan view:clear
git add app/Http/Controllers/Api/EnterpriseController.php routes/api.php tests/Feature/Admin/EnterpriseProvisionSuiteEndpointTest.php
git commit -m "feat: endpoint provision-suite + validaciones de mirror_source_id"
```

---

## Task 4: Generalizar las rutas de las 3 suites al patrón `foreach` dinámico

**Files:**
- Modify: `routes/api.php`
- Test: `tests/Feature/Admin/SuiteRouteParityTest.php`

**Interfaces:**
- Consumes: `Enterprise::mirrorsOf()` (Task 1).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SuiteRouteParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider suiteRoots
     */
    public function test_mirror_gets_same_routes_as_its_root(string $rootSlug, string $mirrorSlug): void
    {
        $root = Enterprise::create(['name' => $rootSlug, 'slug' => $rootSlug, 'description' => 'x', 'is_active' => true]);
        Enterprise::create([
            'name' => $mirrorSlug, 'slug' => $mirrorSlug, 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);

        // Forzar recarga de rutas con los datos ya insertados.
        $this->refreshApplication();

        $rootPaths = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), "{$rootSlug}/"))
            ->map(fn ($r) => Str::after($r->uri(), "{$rootSlug}/"))
            ->sort()->values();

        $mirrorPaths = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), "{$mirrorSlug}/"))
            ->map(fn ($r) => Str::after($r->uri(), "{$mirrorSlug}/"))
            ->sort()->values();

        $this->assertEquals($rootPaths->all(), $mirrorPaths->all());
        $this->assertGreaterThan(0, $mirrorPaths->count());
    }

    public static function suiteRoots(): array
    {
        return [
            'agrícola' => ['splendidfarms', 'finca-modelo-demo-test'],
            'rh' => ['grupoesplendido', 'agroverde-demo-test'],
            'comercio' => ['splendidbyporvenir', 'exportadora-valle-demo-test'],
        ];
    }
}
```

Agregar `use Illuminate\Support\Str;` al inicio del archivo de test.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=SuiteRouteParityTest`
Expected: FAIL en los casos `rh` y `comercio` (sus rutas siguen fijas a `grupoesplendido`/`splendidbyporvenir`, no reconocen la empresa espejo nueva). El caso `agrícola` puede pasar de entrada porque ya usa el loop.

- [ ] **Step 3: Generalizar el bloque RH**

En `routes/api.php`, ubicar `Route::prefix('grupoesplendido')->group(function () {` (línea 931) y envolverlo:

```php
foreach (Enterprise::mirrorsOf('grupoesplendido')->pluck('slug') as $empresaRh) {
    Route::prefix($empresaRh)->group(function () {
        // ... contenido existente sin reindentar, líneas 937-1049 ...
    });
}
```

- [ ] **Step 4: Generalizar el bloque Comercio**

Ídem con `Route::prefix('splendidbyporvenir')->group(function () {` (línea 764):

```php
foreach (Enterprise::mirrorsOf('splendidbyporvenir')->pluck('slug') as $empresaComercio) {
    Route::prefix($empresaComercio)->group(function () {
        // ... contenido existente sin reindentar, líneas 769-929 ...
    });
}
```

- [ ] **Step 5: Agregar el `use` necesario**

Al inicio de `routes/api.php`:

```php
use App\Models\Enterprise;
```

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `php artisan test --filter=SuiteRouteParityTest`
Expected: PASS (los 3 casos)

- [ ] **Step 7: Regresión completa**

Run: `php artisan test`
Expected: todo pasa. Adicionalmente, verificar manualmente que las rutas reales de producción (`splendidfarms`, `grupoesplendido`, `splendidbyporvenir`) no perdieron ninguna ruta:

Run: `php artisan route:list --path=grupoesplendido | wc -l`
Run: `php artisan route:list --path=splendidbyporvenir | wc -l`

Comparar contra el conteo antes de este cambio (anotarlo antes de aplicar el Step 3/4 y volver a correr después).

- [ ] **Step 8: Lint, limpiar cachés y commit**

```bash
php -l routes/api.php
php artisan route:clear && php artisan config:clear
git add routes/api.php tests/Feature/Admin/SuiteRouteParityTest.php
git commit -m "feat: generalizar rutas de RH y Comercio al patrón foreach de empresas espejo"
```

---

## Task 5: Migrar Finca Modelo / Agroverde / Exportadora del Valle al mecanismo nuevo

**Files:**
- Create: `database/migrations/2026_08_24_110000_backfill_mirror_source_id_for_demo_enterprises.php`
- Modify: `routes/api.php`
- Modify: `database/seeders/DemoStructureSeeder.php`

**Interfaces:**
- Consumes: `Enterprise::mirrorsOf()` (Task 1, ya usado por Task 4).

- [ ] **Step 1: Crear la migración de datos**

```php
<?php

use App\Models\Enterprise;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $mirrors = [
        'finca-modelo-demo' => 'splendidfarms',
        'agroverde-demo' => 'grupoesplendido',
        'exportadora-valle-demo' => 'splendidbyporvenir',
    ];

    public function up(): void
    {
        foreach ($this->mirrors as $mirrorSlug => $rootSlug) {
            $root = Enterprise::where('slug', $rootSlug)->first();
            $mirror = Enterprise::where('slug', $mirrorSlug)->first();

            if ($root && $mirror && ! $mirror->mirror_source_id) {
                $mirror->update(['mirror_source_id' => $root->id]);
            }
        }
    }

    public function down(): void
    {
        Enterprise::whereIn('slug', array_keys($this->mirrors))->update(['mirror_source_id' => null]);
    }
};
```

- [ ] **Step 2: Correr la migración contra la BD real de desarrollo**

Antes de correr, respaldar el esquema/datos relevantes igual que en el retrofit anterior:

Run: `"/c/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump.exe" -u root -pMasterKey orkela_crops enterprises > _backups/enterprises_before_mirror_backfill.sql`
Run: `php artisan migrate`

Verificar con tinker:

```bash
php artisan tinker --execute="Enterprise::whereIn('slug', ['finca-modelo-demo','agroverde-demo','exportadora-valle-demo'])->get(['slug','mirror_source_id'])->each(fn(\$e)=>print(\$e->slug.' -> '.\$e->mirror_source_id.PHP_EOL));"
```

Expected: las 3 filas muestran el ID correcto de su raíz (no `null`).

- [ ] **Step 3: Reemplazar el array fijo de la suite agrícola por el mismo patrón `Enterprise::mirrorsOf`**

En `routes/api.php`, el bloque agrícola (línea 174) ya usa `foreach`, pero sobre un array literal:

```php
// Antes
foreach (['splendidfarms', 'finca-modelo-demo'] as $empresaAgricola) {

// Después — mismo patrón data-driven que ya se usa en Task 4 para RH/Comercio
foreach (Enterprise::mirrorsOf('splendidfarms')->pluck('slug') as $empresaAgricola) {
```

- [ ] **Step 4: Actualizar `DemoStructureSeeder` para usar `mirror_source_id` en vez de las funciones `buildXxxSuite` sueltas**

En `database/seeders/DemoStructureSeeder.php`, tras `createDemoEnterprises()`, setear el `mirror_source_id` de cada una apuntando a su raíz real (no la demo) antes de aprovisionar, para que quede consistente con el mecanismo nuevo en instalaciones nuevas:

```php
$splendidFarms = Enterprise::where('slug', 'splendidfarms')->first();
$grupoEsplendido = Enterprise::where('slug', 'grupoesplendido')->first();
$splendidByPorvenir = Enterprise::where('slug', 'splendidbyporvenir')->first();

if ($splendidFarms) {
    $enterprises['fincamodelo']->update(['mirror_source_id' => $splendidFarms->id]);
}
if ($grupoEsplendido) {
    $enterprises['agroverde']->update(['mirror_source_id' => $grupoEsplendido->id]);
}
if ($splendidByPorvenir) {
    $enterprises['exportadoravalle']->update(['mirror_source_id' => $splendidByPorvenir->id]);
}
```

(Esto se agrega antes de las llamadas a `$this->buildCorporateRhSuite(...)` etc., que siguen funcionando igual porque ahora delegan en `EnterpriseProvisioningService` — Task 2.)

- [ ] **Step 5: Verificar en caliente que Finca Modelo sigue funcionando**

Run: `php artisan route:list --path=finca-modelo-demo | wc -l`
Run: `php artisan route:list --path=splendidfarms | wc -l`

Expected: mismo número de rutas que antes de este cambio (ver conteo tomado en Task 4 Step 7 para agrícola, si se anotó; si no, comparar ambos comandos entre sí — deben coincidir).

- [ ] **Step 6: Regresión completa**

Run: `php artisan test`
Expected: 176+ tests pasan (los del retrofit anterior + los nuevos de Tasks 1-4), sin regresiones.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_24_110000_backfill_mirror_source_id_for_demo_enterprises.php routes/api.php database/seeders/DemoStructureSeeder.php
git commit -m "feat: migrar Finca Modelo/Agroverde/Exportadora del Valle al mecanismo mirror_source_id"
```

---

## Task 6: Exponer `mirror_source_slug` en los payloads de empresa

**Files:**
- Modify: `app/Http/Controllers/Api/EnterpriseController.php`
- Modify: `app/Http/Controllers/Api/HierarchicalPermissionController.php`
- Test: `tests/Feature/Admin/EnterpriseMirrorSlugPayloadTest.php`

**Interfaces:**
- Produces: campo `mirror_source_slug` (string|null) en la respuesta de `GET /api/enterprises` (admin), `GET /api/enterprises/{id}` (admin), y en cada elemento de `data.enterprises` de `GET /api/users/{userId}/hierarchical-permissions` (o el endpoint equivalente que llena `userPermissions.enterprises` en el frontend). Consumido por Task 7.

- [ ] **Step 1: Escribir el test que falla**

```php
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
```

(El path exacto del segundo endpoint depende de la ruta real registrada para `HierarchicalPermissionController::getUserPermissions` — verificar con `php artisan route:list --path=hierarchical-permissions` antes de escribir el test y ajustar la URL si difiere.)

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --filter=EnterpriseMirrorSlugPayloadTest`
Expected: FAIL — falta la key `mirror_source_slug` en ambas respuestas.

- [ ] **Step 3: Agregar el campo en `EnterpriseController@index` y `@show`**

En `index()`, ambos `.map()` (admin y usuario normal) agregan, con eager loading para evitar N+1:

```php
$enterprises = Enterprise::with(['activeApplications', 'mirrorSource'])
    ->get()
    ->map(function ($enterprise) {
        return [
            // ...campos existentes...
            'mirror_source_slug' => $enterprise->mirrorSource?->slug,
        ];
    });
```

(Aplicar el mismo `->with([..., 'mirrorSource'])` y el campo nuevo en el bloque de usuario normal y en `show()`.)

- [ ] **Step 4: Agregar el campo en `HierarchicalPermissionController@getUserPermissions`**

Cambiar `->with('enterprise')` (línea 42) por `->with('enterprise.mirrorSource')`, y agregar al `.map()` (línea ~47):

```php
'mirror_source_slug' => $enterprise?->mirrorSource?->slug,
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --filter=EnterpriseMirrorSlugPayloadTest`
Expected: PASS

- [ ] **Step 6: Regresión completa, lint, commit**

Run: `php artisan test`
Run: `php -l app/Http/Controllers/Api/EnterpriseController.php app/Http/Controllers/Api/HierarchicalPermissionController.php`

```bash
git add app/Http/Controllers/Api/EnterpriseController.php app/Http/Controllers/Api/HierarchicalPermissionController.php tests/Feature/Admin/EnterpriseMirrorSlugPayloadTest.php
git commit -m "feat: exponer mirror_source_slug en payloads de empresa"
```

---

## Task 7: Frontend — `ModuleLoader.jsx` resuelve el mirror en runtime

**Files:**
- Modify: `src/components/workspace/ModuleLoader.jsx`

**Interfaces:**
- Consumes: `workspace.enterprise.mirrorSourceSlug` (poblado automáticamente por la conversión snake_case→camelCase de `fetchAPI()` a partir del campo `mirror_source_slug` de Task 6).

- [ ] **Step 1: Eliminar la constante hardcodeada**

Borrar de `ModuleLoader.jsx` (líneas 780-784 actuales):

```js
const DEMO_ENTERPRISE_MIRRORS = {
  "finca-modelo-demo": "splendidfarms",
  "agroverde-demo": "grupoesplendido",
  "exportadora-valle-demo": "splendidbyporvenir",
};
```

- [ ] **Step 2: Cambiar la firma de `resolveRegisteredKey`**

```js
// Antes
const resolveRegisteredKey = (key, enterpriseSlug) => {
  if (REGISTERED_MODULES[key]) return key;

  const mirror = DEMO_ENTERPRISE_MIRRORS[enterpriseSlug];
  if (mirror) {
    const mirroredKey = `${mirror}${key.slice(enterpriseSlug.length)}`;
    if (REGISTERED_MODULES[mirroredKey]) return mirroredKey;
  }

  return null;
};

// Después
const resolveRegisteredKey = (key, enterpriseSlug, mirrorSourceSlug) => {
  if (REGISTERED_MODULES[key]) return key;

  if (mirrorSourceSlug) {
    const mirroredKey = `${mirrorSourceSlug}${key.slice(enterpriseSlug.length)}`;
    if (REGISTERED_MODULES[mirroredKey]) return mirroredKey;
  }

  return null;
};
```

- [ ] **Step 3: Pasar `mirrorSourceSlug` en los 3 call sites**

Cada call site actual (`resolveDetailKey`, `resolveSubmoduleKey`, `resolveModuleKey` — buscar las 3 llamadas a `resolveRegisteredKey(` en el archivo) agrega el tercer argumento:

```js
const resolvedDetailKey = resolveRegisteredKey(detailKey, enterprise?.slug, enterprise?.mirrorSourceSlug);
```

(mismo patrón para `resolvedSubmoduleKey` y `resolvedModuleKey`, reemplazando `detailKey`/`enterprise?.slug` por las variables correspondientes de cada call site — verificar los nombres exactos leyendo las líneas 830-905 del archivo antes de editar, ya que el prefijo del argumento cambia en cada uno).

- [ ] **Step 4: Verificar que `enterprise` viene de `useWorkspace()` en el scope de cada call site**

Los 3 call sites ya están dentro del cuerpo de `ModuleLoader`, que desestructura `enterprise` de `useWorkspace()` (línea 806) — no hace falta importar nada nuevo.

- [ ] **Step 5: Build y lint**

Run: `npm run build`
Expected: compila sin errores.

Run: `npm run lint`
Expected: sin errores nuevos (en particular, sin el error `react-hooks/static-components` — la clave sigue siendo un string devuelto por función, el acceso a `REGISTERED_MODULES[...]` se mantiene inline en cada call site, no se tocó esa parte).

- [ ] **Step 6: Verificación manual en navegador**

Con el backend de Tasks 1-6 corriendo y Finca Modelo ya con `mirror_source_id` seteado (Task 5):
1. Loguearse, entrar a Finca Modelo → Operación Agrícola → Cosecha.
2. Confirmar que los submódulos cargan igual que antes de este cambio (sin la constante hardcodeada).

- [ ] **Step 7: Commit**

```bash
git add src/components/workspace/ModuleLoader.jsx
git commit -m "feat: ModuleLoader resuelve el mirror de suite en runtime, elimina DEMO_ENTERPRISE_MIRRORS"
```

---

## Task 8: Frontend — selector de suite + botón de aprovisionamiento en el admin panel

**Files:**
- Modify: `src/services/api.js`
- Modify: `src/components/admin/EnterpriseFormModal.jsx`

**Interfaces:**
- Consumes: `POST /api/enterprises/{id}/provision-suite` (Task 3), `GET /api/enterprises` con `mirror_source_slug`/`id` de cada fila (Task 6).
- Produces: `enterprisesAPI.provisionSuite(id): Promise<{status, message, data}>`.

- [ ] **Step 1: Agregar la función al servicio**

En `src/services/api.js`, dentro de `enterprisesAPI` (junto a `delete`):

```js
provisionSuite: async (id) => {
  return fetchAPI(`/enterprises/${id}/provision-suite`, {
    method: "POST",
  });
},
```

- [ ] **Step 2: Agregar el selector de suite al formulario**

En `EnterpriseFormModal.jsx`, agregar estado y un `SearchableSelect` (importado desde `../sistema`) con las 3 raíces conocidas más la opción "Ninguna":

```js
const SUITE_ROOTS = [
  { value: "", label: "Ninguna (empresa independiente)" },
  { value: "splendidfarms", label: "Agrícola (espejo de Splendid Farms)" },
  { value: "grupoesplendido", label: "RH (espejo de Grupo Espléndido)" },
  { value: "splendidbyporvenir", label: "Comercio (espejo de Splendid by Porvenir)" },
];
```

El valor seleccionado se guarda como `mirror_source_slug` en el estado local del form; al enviar el `FormData` a `enterprisesAPI.create`/`update`, se resuelve a `mirror_source_id` numérico buscando en la lista de empresas ya cargada por el listado (`enterprises` prop, filtrando por `slug` y `mirror_source_slug == null` para quedarse solo con raíces) antes de hacer `data.append("mirror_source_id", ...)`.

El selector se deshabilita (`isDisabled`) cuando `enterprise?.applicationsCount > 0` (ya aprovisionada) — usa el campo que ya devuelve `EnterpriseController@index` (`applications_count` → `applicationsCount` tras la conversión automática).

- [ ] **Step 3: Agregar el botón de aprovisionamiento**

Visible solo en modo edición (`enterprise` no es null) y cuando `mirror_source_slug` tiene valor:

```jsx
const [provisioning, setProvisioning] = useState(false);
const { success, error: alertError } = useAlert();

const handleProvision = async () => {
  setProvisioning(true);
  try {
    const res = await enterprisesAPI.provisionSuite(enterprise.id);
    const { applications, modules, submodules } = res.data.created;
    success(
      applications || modules || submodules
        ? `Estructura creada: ${applications} aplicaciones, ${modules} módulos, ${submodules} submódulos.`
        : "La estructura ya estaba al día — nada nuevo que crear.",
    );
  } catch (err) {
    alertError(err.message || "No se pudo aprovisionar la suite.");
  } finally {
    setProvisioning(false);
  }
};
```

```jsx
{enterprise && formData.mirror_source_slug && (
  <Button type="button" onClick={handleProvision} disabled={provisioning}>
    {provisioning
      ? "Aprovisionando..."
      : enterprise.applicationsCount > 0
        ? "Re-sincronizar estructura"
        : "Aprovisionar suite"}
  </Button>
)}
```

(Usar el componente `Button` de `../sistema`, ya importado en otros modales del proyecto — verificar el import exacto existente en el archivo antes de agregarlo.)

- [ ] **Step 4: Build y lint**

Run: `npm run build`
Run: `npm run lint`
Expected: sin errores.

- [ ] **Step 5: Verificación manual en navegador**

1. Admin → Empresas → crear una empresa nueva, elegir "RH (espejo de Grupo Espléndido)".
2. Guardar, volver a abrir el formulario de edición, click en "Aprovisionar suite".
3. Confirmar el toast de éxito con el resumen (`useAlert().success`) y que el selector de suite ahora aparece deshabilitado.
4. Click de nuevo en "Re-sincronizar estructura" → confirmar que el resumen ahora dice "nada nuevo que crear".

- [ ] **Step 6: Commit**

```bash
git add src/services/api.js src/components/admin/EnterpriseFormModal.jsx
git commit -m "feat: selector de suite + aprovisionamiento visual en EnterpriseFormModal"
```

---

## Task 9: Frontend — badge de suite en el listado de empresas

**Files:**
- Modify: `src/views/admin/Enterprises.jsx`

**Interfaces:**
- Consumes: `mirror_source_slug` (→ `mirrorSourceSlug`) de cada fila devuelta por `enterprisesAPI.getAll()` (Task 6).

- [ ] **Step 1: Agregar un mapa de etiquetas legibles**

```js
const SUITE_LABELS = {
  splendidfarms: "Espejo · Agrícola",
  grupoesplendido: "Espejo · RH",
  splendidbyporvenir: "Espejo · Comercio",
};
```

- [ ] **Step 2: Renderizar el badge en cada fila/card de empresa**

```jsx
<Badge variant={enterprise.mirrorSourceSlug ? "info" : "neutral"}>
  {enterprise.mirrorSourceSlug ? SUITE_LABELS[enterprise.mirrorSourceSlug] ?? "Espejo" : "Independiente"}
</Badge>
```

(Usar el componente `Badge` de `../../components/sistema`, verificando las variantes disponibles ya usadas en el archivo antes de elegir `"info"`/`"neutral"` — ajustar a las variantes reales si difieren.)

- [ ] **Step 3: Build, lint y verificación visual**

Run: `npm run build && npm run lint`

Verificación manual: el listado de empresas muestra "Espejo · Agrícola" en Finca Modelo, "Espejo · RH" en Agroverde, "Espejo · Comercio" en Exportadora del Valle, e "Independiente" en Splendid Farms/Grupo Espléndido/Splendid by Porvenir.

- [ ] **Step 4: Commit**

```bash
git add src/views/admin/Enterprises.jsx
git commit -m "feat: badge de suite espejo en el listado de empresas"
```

---

## Task 10: Audit — aislamiento real de la suite RH (Grupo Espléndido)

**Files:**
- Read/modify as needed: `app/Http/Controllers/Api/GrupoEsplendido/RH/*.php`
- Create (if needed): fixes siguiendo el patrón trait+backfill del retrofit agrícola anterior
- Test: `tests/Feature/Admin/RhSuiteIsolationTest.php`

**Interfaces:**
- Consumes: la empresa espejo de RH creada en Task 5 (`agroverde-demo`, ya con `mirror_source_id` apuntando a `grupoesplendido`).

- [ ] **Step 1: Escribir el test de aislamiento end-to-end**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RhSuiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_created_for_mirror_is_invisible_from_root(): void
    {
        $root = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Agroverde', 'slug' => 'agroverde-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        $this->postJson("/api/agroverde-demo/rh/departamentos", [
            'enterprise_id' => $mirror->id,
            'name' => 'Departamento de prueba espejo',
        ])->assertStatus(201);

        $this->assertSame(1, Department::where('enterprise_id', $mirror->id)->count());
        $this->assertSame(0, Department::where('enterprise_id', $root->id)->count());
    }
}
```

(Ajustar el payload mínimo requerido por `DepartmentController::store` leyendo su `$request->validate([...])` real antes de correr — el ejemplo de arriba asume los campos mínimos vistos en la sección 2 de la spec; puede necesitar más campos obligatorios.)

- [ ] **Step 2: Correr el test**

Run: `php artisan test --filter=RhSuiteIsolationTest`

- [ ] **Step 3: Si falla, auditar el controller**

Leer `app/Http/Controllers/Api/GrupoEsplendido/RH/DepartmentController.php` completo (y los demás controllers de RH: `PositionController`, `EmployeeController`, `WorkScheduleController`, `AttendanceController`, `TimeClockController`, `VacationController`, `IncidentController`, `IncidentTypeController`) buscando:
- Cualquier `Enterprise::where('slug', 'grupoesplendido')` hardcodeado.
- Cualquier default de `enterprise_id` que no venga del request cuando falta.
- Cualquier query sin `->where('enterprise_id', ...)` que debería tenerlo.

Si se encuentra un hardcode, corregirlo reemplazándolo por el `enterprise_id` validado del request (mismo patrón que ya usa `DepartmentController@index`, que sí filtra correctamente) — **no** aplicar el trait `BelongsToEnterprise` acá: esta suite ya resuelve por `enterprise_id` explícito, agregar un global scope encima duplicaría el mecanismo y podría generar los mismos conflictos que se vieron con el módulo biométrico en el retrofit agrícola anterior.

- [ ] **Step 4: Re-correr hasta que pase**

Run: `php artisan test --filter=RhSuiteIsolationTest`
Expected: PASS

- [ ] **Step 5: Regresión completa**

Run: `php artisan test`

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Admin/RhSuiteIsolationTest.php
# + cualquier archivo de app/Http/Controllers/Api/GrupoEsplendido/RH/ corregido
git commit -m "test: verificar aislamiento real de la suite RH entre empresa raíz y espejo"
```

---

## Task 11: Comercio — corregir el slug hardcodeado de Etiquetas SSCC (único controller propio activo)

**Contexto del audit** (ya resuelto durante la redacción de este plan, no hace
falta investigar en la ejecución): `grep -n "SplendidByPorvenir\\\\"
routes/api.php` muestra que Administración e Inventario del bloque
`splendidbyporvenir` reutilizan literalmente los controllers de
`SplendidFarms\...` — ya heredan el aislamiento del retrofit agrícola, no
requieren nada acá. Los grupos `exports` y `purchases` (líneas 917-929) están
**vacíos** (solo comentarios `// Rutas de exportaciones...` / `// Rutas de
compras...`, ningún controller registrado) — no hay nada que auditar ahí
todavía. El único controller propio con rutas reales es
`Sales\SsccLabelController`, y su método privado `resolveEnterpriseId()` ya
lee la empresa desde el header `X-Enterprise-Slug` (con fallback a
`splendidbyporvenir` si el header no llega) — el backend **ya está
aislado correctamente**, no necesita trait ni migración.

El único bug real es en el frontend: `useSsccLabels.js` tiene tanto la URL
base como el header hardcodeados al literal `"splendidbyporvenir"`, por lo
que una empresa espejo (`exportadora-valle-demo`) nunca podría llamar a este
endpoint con su propio slug — mismo patrón de bug que los ~78 archivos
corregidos en el retrofit agrícola anterior.

**Files:**
- Modify: `src/hooks/splendidbyporvenir/sales/gestion-producto/useSsccLabels.js`
- Test: `tests/Feature/Admin/TradeSuiteIsolationTest.php`

**Interfaces:**
- Consumes: `getCurrentEnterpriseSlug()` (`src/services/api.js`, ya usado por el fix equivalente de la suite agrícola).
- Consumes: la empresa espejo de Comercio creada en Task 5 (`exportadora-valle-demo`).

- [ ] **Step 1: Escribir el test de aislamiento end-to-end (backend)**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Enterprise;
use App\Models\SalesSsccLabel;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\EnterpriseProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradeSuiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sscc_label_scope_follows_the_x_enterprise_slug_header(): void
    {
        $root = Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'x', 'is_active' => true]);
        $mirror = Enterprise::create([
            'name' => 'Exportadora del Valle', 'slug' => 'exportadora-valle-demo', 'description' => 'x',
            'is_active' => true, 'mirror_source_id' => $root->id,
        ]);
        app(EnterpriseProvisioningService::class)->provision($mirror);

        $user = User::factory()->create();
        UserEnterpriseAccess::create(['user_id' => $user->id, 'enterprise_id' => $mirror->id, 'is_active' => true, 'granted_at' => now()]);
        Sanctum::actingAs($user);

        // Simula lo que hará el frontend ya corregido: manda el slug real de
        // la empresa activa en el header, no el literal 'splendidbyporvenir'.
        $this->getJson('/api/exportadora-valle-demo/sales/gestion-producto/etiquetas-sscc', [
            'X-Enterprise-Slug' => 'exportadora-valle-demo',
        ])->assertStatus(200);

        SalesSsccLabel::factory()->count(2)->create(['enterprise_id' => $mirror->id]);
        SalesSsccLabel::factory()->count(3)->create(['enterprise_id' => $root->id]);

        $response = $this->getJson('/api/exportadora-valle-demo/sales/gestion-producto/etiquetas-sscc', [
            'X-Enterprise-Slug' => 'exportadora-valle-demo',
        ])->assertStatus(200);

        $this->assertCount(2, $response->json('data.data') ?? $response->json('data'));
    }
}
```

(Si `SalesSsccLabel` no tiene factory, crear los 5 registros con
`SalesSsccLabel::create([...])` directamente, revisando los `$fillable`
reales del modelo antes de completar los campos obligatorios.)

- [ ] **Step 2: Correr el test**

Run: `php artisan test --filter=TradeSuiteIsolationTest`
Expected: PASS ya en este punto — confirma que el backend (`resolveEnterpriseId()`) ya estaba correctamente aislado; este test queda como regresión permanente.

- [ ] **Step 3: Corregir el hardcode del frontend**

En `src/hooks/splendidbyporvenir/sales/gestion-producto/useSsccLabels.js`:

```js
// Antes
import { useCallback, useState } from "react";
import { fetchAPI } from "../../../../services/api";

const BASE_URL = "/splendidbyporvenir/sales/gestion-producto/etiquetas-sscc";

const contextHeaders = {
  "X-Enterprise-Slug": "splendidbyporvenir",
  "X-Application-Slug": "sales",
  "X-Module-Slug": "gestion-producto",
  "X-Submodule-Slug": "etiquetas-sscc",
};

// Después
import { useCallback, useState } from "react";
import { fetchAPI, getCurrentEnterpriseSlug } from "../../../../services/api";

const getBaseUrl = () =>
  `/${getCurrentEnterpriseSlug()}/sales/gestion-producto/etiquetas-sscc`;

const getContextHeaders = () => ({
  "X-Enterprise-Slug": getCurrentEnterpriseSlug(),
  "X-Application-Slug": "sales",
  "X-Module-Slug": "gestion-producto",
  "X-Submodule-Slug": "etiquetas-sscc",
});
```

Reemplazar cada uso de `BASE_URL` por `getBaseUrl()` y cada uso de
`contextHeaders` por `getContextHeaders()` dentro del cuerpo del hook
(buscar todas las ocurrencias en el archivo — son las mismas llamadas ya
usadas en `fetchLabels`, `previewExcel`, `importExcel`, `createManual`,
`markPrinted`, `destroyByManifest`, `destroy`, siguiendo exactamente el
patrón ya aplicado a los hooks de la suite agrícola en el retrofit
anterior).

- [ ] **Step 4: Build y lint**

Run: `npm run build`
Run: `npm run lint`
Expected: sin errores.

- [ ] **Step 5: Regresión completa backend**

Run: `php artisan test`

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Admin/TradeSuiteIsolationTest.php
git add ../orkela-crops-front/src/hooks/splendidbyporvenir/sales/gestion-producto/useSsccLabels.js
git commit -m "fix: useSsccLabels deja de hardcodear splendidbyporvenir, usa getCurrentEnterpriseSlug()"
```

(Nota: `exports` y `purchases` quedan fuera de este plan — no tienen ningún
controller registrado todavía. Cuando se construyan, deben seguir el mismo
patrón de `resolveEnterpriseId()` por header desde el día uno, no requerirán
retrofit posterior.)

---

## Task 12: Verificación manual end-to-end en navegador (los 3 mirrors)

**Files:** ninguno — solo verificación, sin cambios de código.

- [ ] **Step 1: Levantar backend y frontend**

Backend: `php artisan serve` (o `composer dev`)
Frontend: `npm run dev`

- [ ] **Step 2: Verificar la suite Agrícola (Finca Modelo)**

1. Loguearse como usuario con acceso a Finca Modelo.
2. Entrar a Operación Agrícola → Cosecha → confirmar que carga sin error (mismo caso reportado al inicio de esta iniciativa).
3. Crear un registro de prueba, confirmar en la pestaña Network que la request va a `/api/finca-modelo-demo/...` y responde 200.

- [ ] **Step 3: Verificar la suite RH (Agroverde)**

1. Loguearse como usuario con acceso a Agroverde.
2. Entrar a RH → Empleados → Lista de Empleados, confirmar que carga (vista compartida de Grupo Espléndido, resuelta vía `mirrorSourceSlug`).
3. Confirmar en Network que la request va a `/api/agroverde-demo/rh/...`.

- [ ] **Step 4: Verificar la suite Comercio (Exportadora del Valle)**

1. Loguearse como usuario con acceso a Exportadora del Valle.
2. Entrar a Ventas → Clientes → Catálogo de Clientes, confirmar que carga.
3. Confirmar en Network que la request va a `/api/exportadora-valle-demo/sales/...`.

- [ ] **Step 5: Verificar el flujo de aprovisionamiento visual con una empresa nueva de prueba**

1. Admin → Empresas → crear "QA Suite Test", suite = Agrícola.
2. Click "Aprovisionar suite" → confirmar toast de éxito.
3. Verificar que aparece en el selector de empresas del usuario admin (tras refrescar permisos) y que sus vistas de la suite agrícola cargan igual que Finca Modelo.
4. Borrar "QA Suite Test" al terminar (no dejar basura de prueba en la BD de desarrollo).

- [ ] **Step 6: Reportar el resultado**

Sin commit en este task — es solo verificación. Documentar cualquier hallazgo como issue separado si algo no funciona como se espera.
