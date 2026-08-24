# Piloto de multi-tenancy: Cuentas por Pagar — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que `accounts_payable` (Contabilidad → Cuentas por Pagar) sea multi-tenant real: cada empresa ve y edita solo sus propios documentos, sin tocar los datos existentes de Splendid Farms. Sirve como piloto para validar el patrón antes de repetirlo en las otras 72 tablas identificadas en el spec.

**Architecture:** Middleware nuevo resuelve la empresa actual a partir del primer segmento de la URL y la deja disponible en el container (`app()->instance('currentEnterprise', ...)`). Un trait `BelongsToEnterprise` (mismo patrón que el `Loggable` ya existente) agrega un global scope de Eloquent al modelo `AccountPayable` — así todas las queries existentes del controller quedan filtradas automáticamente, sin tener que tocar cada método uno por uno. Las rutas de Contabilidad pasan de un único prefijo hardcodeado a un loop sobre las empresas autorizadas.

**Tech Stack:** Laravel 12, PHPUnit (SQLite in-memory para tests), MySQL en desarrollo/producción.

**Spec:** `docs/superpowers/specs/2026-08-23-agricultural-suite-multi-tenancy-design.md`

## Global Constraints

- Nunca correr `php artisan test`/`migrate` con `--env=...` (incidente real de pérdida de datos documentado en `CLAUDE.md`).
- Antes de migrar sobre la base real, correr `php artisan migrate --pretend` primero.
- Los tests corren en SQLite in-memory (`phpunit.xml`) — si una migración usa sintaxis específica de MySQL, hay que guardarla con `if (DB::getDriverName() === 'mysql')`.
- Después de cada cambio: `php artisan route:clear && php artisan config:clear && php artisan view:clear` + `php -l` sobre el archivo tocado.
- Todas las entidades de negocio usan `Loggable` + `SoftDeletes` — no romper ese patrón en `AccountPayable`.

---

### Task 1: Migración — agregar `enterprise_id` a `accounts_payable` y backfillar

**Files:**
- Create: `database/migrations/2026_08_23_100000_add_enterprise_id_to_accounts_payable_table.php`
- Test: `tests/Feature/Accounting/AccountPayableMultiTenancyTest.php` (se crea vacío en este task, se completa en el Task 5)

**Interfaces:**
- Consumes: tabla `accounts_payable` existente, tabla `enterprises` existente (columna `slug`).
- Produces: columna `accounts_payable.enterprise_id` (FK a `enterprises.id`, `NOT NULL` al final de la migración), índice sobre esa columna.

- [ ] **Step 1: Escribir la migración**

```php
<?php

use App\Models\Enterprise;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Columna nullable primero — no podemos exigir NOT NULL sobre
        //    filas que ya existen sin ese dato.
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->foreignId('enterprise_id')
                ->nullable()
                ->after('id')
                ->constrained('enterprises')
                ->cascadeOnDelete();
        });

        // 2. Backfill: todo lo que ya existe en esta tabla es de Splendid
        //    Farms — es la única empresa que usa Contabilidad hoy (ver
        //    routes/api.php, Contabilidad solo está bajo el prefijo
        //    'splendidfarms').
        $splendidFarms = Enterprise::where('slug', 'splendidfarms')->first();

        if ($splendidFarms) {
            DB::table('accounts_payable')
                ->whereNull('enterprise_id')
                ->update(['enterprise_id' => $splendidFarms->id]);
        }

        // 3. Ahora que todo tiene valor, la columna pasa a NOT NULL.
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts_payable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
        });
    }
};
```

- [ ] **Step 2: Verificar en seco antes de aplicar**

Run: `php artisan migrate --pretend`
Expected: se listan los `ALTER TABLE` de la migración nueva, sin errores. **No correr `migrate` real todavía** — falta el resto del piloto.

- [ ] **Step 3: Crear el archivo de test vacío (se completa en Task 5)**

```php
<?php

namespace Tests\Feature\Accounting;

use Tests\TestCase;

class AccountPayableMultiTenancyTest extends TestCase
{
    // Se completa en el Task 5, después de tener el middleware y el trait.
}
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_23_100000_add_enterprise_id_to_accounts_payable_table.php tests/Feature/Accounting/AccountPayableMultiTenancyTest.php
git commit -m "feat: add enterprise_id column to accounts_payable (nullable->backfill->not null)"
```

---

### Task 2: Middleware — resolver la empresa actual desde la URL

**Files:**
- Create: `app/Http/Middleware/ResolveCurrentEnterprise.php`
- Modify: `bootstrap/app.php:16-20` (registrar el alias del middleware)

**Interfaces:**
- Consumes: `Enterprise` model (`slug` column), primer segmento de la URL (`$request->segment(1)`).
- Produces: binding en el container `app('currentEnterprise')` (instancia de `Enterprise` o `null`), disponible para el Task 3 (`BelongsToEnterprise` lo lee de ahí).

- [ ] **Step 1: Escribir el middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Models\Enterprise;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa actual a partir del primer segmento de la URL
 * (ej. /api/finca-modelo-demo/... -> slug 'finca-modelo-demo') y la deja
 * disponible en el container como 'currentEnterprise'. Los modelos que usan
 * el trait BelongsToEnterprise leen de acá para su global scope — no hay
 * que resolver la empresa de nuevo en cada controller.
 */
class ResolveCurrentEnterprise
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->segment(1);

        if ($slug) {
            $enterprise = Enterprise::where('slug', $slug)->first();

            if ($enterprise) {
                app()->instance('currentEnterprise', $enterprise);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Registrar el middleware en el stack de `api`**

En `bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware) { ... })`, agregar después de la línea existente `$middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);`:

```php
        $middleware->appendToGroup('api', \App\Http\Middleware\ResolveCurrentEnterprise::class);
```

- [ ] **Step 3: Lint**

Run: `php -l app/Http/Middleware/ResolveCurrentEnterprise.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/ResolveCurrentEnterprise.php bootstrap/app.php
git commit -m "feat: add ResolveCurrentEnterprise middleware"
```

---

### Task 3: Trait `BelongsToEnterprise` — global scope + auto-asignación al crear

**Files:**
- Create: `app/Traits/BelongsToEnterprise.php`

**Interfaces:**
- Consumes: `app('currentEnterprise')` (del Task 2), columna `enterprise_id` en el modelo que use el trait.
- Produces: global scope `'enterprise'` aplicado automáticamente a toda query Eloquent del modelo (`Model::all()`, `Model::where(...)`, route model binding, etc.); auto-asigna `enterprise_id` en `creating` si no se seteó explícitamente; relación `enterprise(): BelongsTo`.

- [ ] **Step 1: Escribir el trait**

```php
<?php

namespace App\Traits;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mismo patrón que Loggable (bootXxx auto-invocado por Eloquent). Agrega
 * un global scope que filtra CUALQUIER query del modelo por la empresa
 * actual (resuelta por ResolveCurrentEnterprise) — no hay que acordarse de
 * agregar ->where('enterprise_id', ...) en cada método de cada controller,
 * es automático e incluye el route model binding.
 *
 * Requiere que el modelo tenga columna `enterprise_id` (ver migración de
 * cada tabla) y la agregue a su $fillable.
 */
trait BelongsToEnterprise
{
    public static function bootBelongsToEnterprise(): void
    {
        static::addGlobalScope('enterprise', function (Builder $builder) {
            $enterprise = app()->bound('currentEnterprise') ? app('currentEnterprise') : null;

            if ($enterprise) {
                $builder->where($builder->getModel()->getTable().'.enterprise_id', $enterprise->id);
            }
        });

        static::creating(function ($model) {
            if (! $model->enterprise_id && app()->bound('currentEnterprise')) {
                $currentEnterprise = app('currentEnterprise');
                if ($currentEnterprise) {
                    $model->enterprise_id = $currentEnterprise->id;
                }
            }
        });
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l app/Traits/BelongsToEnterprise.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Traits/BelongsToEnterprise.php
git commit -m "feat: add BelongsToEnterprise trait (global scope + auto-assign on create)"
```

---

### Task 4: Aplicar el trait al modelo `AccountPayable`

**Files:**
- Modify: `app/Models/AccountPayable.php:1-20`

**Interfaces:**
- Consumes: `App\Traits\BelongsToEnterprise` (Task 3).
- Produces: `AccountPayable` queda scopeado por empresa; `AccountPayable::create([...])` sin `enterprise_id` explícito lo auto-asigna.

- [ ] **Step 1: Agregar el trait y el campo a `$fillable`**

En `app/Models/AccountPayable.php`, cambiar:

```php
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Cuentas por Pagar
 * Ubicación: accounting/cuentas-por-pagar
 */
class AccountPayable extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'accounts_payable';

    protected $fillable = [
        'document_number',
```

por:

```php
use App\Traits\BelongsToEnterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Cuentas por Pagar
 * Ubicación: accounting/cuentas-por-pagar
 */
class AccountPayable extends Model
{
    use BelongsToEnterprise, HasFactory, Loggable, SoftDeletes;

    protected $table = 'accounts_payable';

    protected $fillable = [
        'enterprise_id',
        'document_number',
```

- [ ] **Step 2: Lint**

Run: `php -l app/Models/AccountPayable.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Models/AccountPayable.php
git commit -m "feat: scope AccountPayable by enterprise via BelongsToEnterprise"
```

---

### Task 5: Rutas — Contabilidad disponible para Finca Modelo además de Splendid Farms

**Files:**
- Modify: `routes/api.php:466-493` (bloque `Route::prefix('contabilidad')` dentro de `Route::prefix('splendidfarms')`)

**Interfaces:**
- Consumes: array de slugs autorizados para la suite agrícola.
- Produces: las mismas rutas de Contabilidad, registradas también bajo `finca-modelo-demo`.

- [ ] **Step 1: Sacar el bloque de Contabilidad del `Route::prefix('splendidfarms')` y envolverlo en un loop**

Ubicar (dentro de `Route::prefix('splendidfarms')->group(function () { ... })`, alrededor de la línea 466):

```php
        Route::prefix('contabilidad')->group(function () {
            Route::prefix('cxp')->group(function () {
                Route::prefix('documentos')->group(function () {
                    // ... rutas existentes, sin cambios ...
                });
                Route::apiResource('documentos', App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class)
                    ->parameters(['documentos' => 'accountPayable']);
            });
        });
```

Sacarlo de adentro de `splendidfarms` y ponerlo **antes** del `Route::prefix('splendidfarms')->group(...)`, envuelto así (el contenido interno de `Route::prefix('contabilidad')->group(...)` no cambia, solo lo que lo envuelve):

```php
// Contabilidad: disponible para Splendid Farms y su espejo demo (Finca
// Modelo) — piloto del retrofit de multi-tenancy, ver
// docs/superpowers/specs/2026-08-23-agricultural-suite-multi-tenancy-design.md.
// El resto de la suite agrícola (administración, inventario, operación
// agrícola) sigue solo bajo 'splendidfarms' hasta que se repita este mismo
// patrón en esos dominios.
foreach (['splendidfarms', 'finca-modelo-demo'] as $empresaContabilidad) {
    Route::prefix($empresaContabilidad)->group(function () {
        Route::prefix('contabilidad')->group(function () {
            Route::prefix('cxp')->group(function () {
                Route::prefix('documentos')->group(function () {
                    // ... el mismo contenido que ya estaba acá, sin tocarlo ...
                });
                Route::apiResource('documentos', App\Http\Controllers\Api\SplendidFarms\Accounting\AccountPayableController::class)
                    ->parameters(['documentos' => 'accountPayable']);
            });
        });
    });
}
```

Y borrar el bloque `Route::prefix('contabilidad')->group(...)` que quedó adentro de `Route::prefix('splendidfarms')` (ya no hace falta, el `foreach` ya cubre `'splendidfarms'`).

- [ ] **Step 2: Verificar que las rutas quedaron registradas para ambas empresas**

Run: `php artisan route:list --path=contabilidad`
Expected: cada ruta aparece dos veces, una con prefijo `splendidfarms/contabilidad/...` y otra con `finca-modelo-demo/contabilidad/...`.

- [ ] **Step 3: Limpiar caches de ruta**

Run: `php artisan route:clear && php artisan config:clear`

- [ ] **Step 4: Commit**

```bash
git add routes/api.php
git commit -m "feat: register Contabilidad routes for finca-modelo-demo (multi-tenancy pilot)"
```

---

### Task 6: Test de aislamiento — Finca Modelo no ve ni afecta los documentos de Splendid Farms

**Files:**
- Modify: `tests/Feature/Accounting/AccountPayableMultiTenancyTest.php` (creado vacío en Task 1)
- Referencia de fixtures: `tests/Concerns/CreatesAssetFixtures.php` (patrón existente a seguir para crear un supplier/entity mínimos)

**Interfaces:**
- Consumes: `AccountPayable::create()`, endpoint `GET /api/{empresa}/contabilidad/cxp/documentos`.
- Produces: prueba automatizada de que el scope funciona en ambas direcciones.

- [ ] **Step 1: Escribir el test**

Ninguno de `Enterprise`, `Supplier` ni `AccountPayable` tiene factory todavía (`database/factories/` no las tiene) — el test arma los fixtures a mano con `::create()`, con todas las columnas requeridas de cada migración (`enterprises`: `slug`, `name`, `description`; `suppliers`: `code`, `business_name`; `accounts_payable`: `document_number`, `supplier_id`, `document_date`, `due_date`, `subtotal`, `total_amount`, `balance`, `status`). `Supplier` todavía NO tiene `enterprise_id` (es de otra fase — Inventario), así que en este test se comparte un único proveedor global entre ambas empresas; lo único que se prueba scopeado acá es `AccountPayable`.

```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountPayable;
use App\Models\Enterprise;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountPayableMultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function makeEnterprise(string $slug): Enterprise
    {
        return Enterprise::create([
            'slug' => $slug,
            'name' => $slug,
            'description' => 'Empresa de prueba',
        ]);
    }

    private function makeSupplier(): Supplier
    {
        static $n = 0;
        $n++;

        return Supplier::create([
            'code' => "SUP-TEST-{$n}",
            'business_name' => "Proveedor de prueba {$n}",
        ]);
    }

    private function makeAccountPayable(string $documentNumber, int $supplierId): AccountPayable
    {
        return AccountPayable::create([
            'document_number' => $documentNumber,
            'supplier_id' => $supplierId,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 1000,
            'total_amount' => 1160,
            'balance' => 1160,
            'status' => AccountPayable::STATUS_PENDING,
        ]);
    }

    public function test_finca_modelo_no_ve_documentos_de_splendid_farms(): void
    {
        $splendidFarms = $this->makeEnterprise('splendidfarms');
        $fincaModelo = $this->makeEnterprise('finca-modelo-demo');
        $supplier = $this->makeSupplier();

        app()->instance('currentEnterprise', $splendidFarms);
        $docSf = $this->makeAccountPayable('DOC-SF-001', $supplier->id);

        app()->instance('currentEnterprise', $fincaModelo);
        $docFm = $this->makeAccountPayable('DOC-FM-001', $supplier->id);

        Sanctum::actingAs(User::factory()->create());

        // Como Finca Modelo: solo debe ver su propio documento.
        $response = $this->getJson('/api/finca-modelo-demo/contabilidad/cxp/documentos');

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($docFm->id));
        $this->assertFalse($ids->contains($docSf->id));
    }

    public function test_finca_modelo_no_puede_acceder_a_documento_de_splendid_farms_por_id(): void
    {
        $splendidFarms = $this->makeEnterprise('splendidfarms');
        $this->makeEnterprise('finca-modelo-demo');
        $supplier = $this->makeSupplier();

        app()->instance('currentEnterprise', $splendidFarms);
        $docSf = $this->makeAccountPayable('DOC-SF-002', $supplier->id);

        Sanctum::actingAs(User::factory()->create());

        // Ruta de Finca Modelo pidiendo el ID de un documento de Splendid
        // Farms: el route model binding usa el global scope, así que no lo
        // encuentra -> 404, no 200 con datos ajenos.
        $response = $this->getJson("/api/finca-modelo-demo/contabilidad/cxp/documentos/{$docSf->id}");

        $response->assertNotFound();
    }
}
```

Si `User::factory()` tampoco existe todavía, revisar `database/factories/UserFactory.php` (Laravel la trae por defecto en el scaffold estándar — debería ya estar) antes de asumir que hay que crearla.

- [ ] **Step 2: Correr el test — confirmar que falla primero por lo esperado (antes de aplicar el trait fallaría distinto; en este punto del plan el trait ya existe, así que debería pasar)**

Run: `php artisan test --filter=AccountPayableMultiTenancyTest`
Expected: 2 tests, ambos PASS. Si falla, revisar en este orden: (1) ¿la migración del Task 1 corrió en la BD de test? (`RefreshDatabase` la aplica sola), (2) ¿el middleware del Task 2 está en el grupo `api`?, (3) ¿el trait del Task 3 está aplicado al modelo?

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Accounting/AccountPayableMultiTenancyTest.php
git commit -m "test: verify AccountPayable enterprise isolation"
```

---

### Task 7: Verificación manual end-to-end

**Files:** ninguno — solo verificación, sin cambios de código.

- [ ] **Step 1: Backup antes de tocar la base real**

Run (ajustar credenciales según `.env`): `mysqldump -u root -pMasterKey sentinel accounts_payable > backup_accounts_payable_pre_migracion.sql`

- [ ] **Step 2: Migrar en seco una vez más, ahora con todo el piloto completo**

Run: `php artisan migrate --pretend`
Expected: solo aparece la migración del Task 1 (las demás tareas no son migraciones).

- [ ] **Step 3: Migrar de verdad**

Run: `php artisan migrate`
Expected: la migración corre sin error; `SELECT COUNT(*) FROM accounts_payable WHERE enterprise_id IS NULL;` en MySQL debe dar 0.

- [ ] **Step 4: Correr el resto de la suite para confirmar que nada de Splendid Farms se rompió**

Run: `php artisan test --filter=SplendidFarms`
Expected: mismos resultados que antes del piloto (no debería haber regresiones — `AccountPayable` scopeado por `splendidfarms` sigue viendo exactamente lo mismo que veía antes, porque todo lo existente se backfilleó a ese `enterprise_id`).

- [ ] **Step 5: Verificación manual vía frontend**

Con el frontend ya corriendo (los ~78 archivos arreglados esta sesión), loguearse como `demo@orkelacrops.com`, entrar a Finca Modelo → Contabilidad → Cuentas por Pagar, y confirmar: (a) la vista real carga (no la genérica), (b) la lista está vacía (dato aislado, no el de Splendid Farms), (c) se puede crear un documento nuevo y aparece scopeado a Finca Modelo.

---

## Después del piloto

Si esto funciona bien, repetir el mismo patrón (Tasks 1, 4, 5 — los Tasks 2 y 3 ya quedan hechos, son reutilizables) para el siguiente dominio más chico (Personal-SF, 6 tablas), y así sucesivamente según el orden sugerido en el spec. Cada dominio es su propio plan nuevo, no una extensión de este.
