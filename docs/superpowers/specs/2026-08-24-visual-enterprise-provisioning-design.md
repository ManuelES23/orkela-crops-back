# Aprovisionamiento visual de empresas nuevas (espejo de suite) — Design Spec

## Contexto

Tras el retrofit de multi-tenancy de la suite agrícola (ver
[2026-08-23-agricultural-suite-multi-tenancy-design.md](2026-08-23-agricultural-suite-multi-tenancy-design.md)),
Finca Modelo quedó funcionando como "empresa espejo" de Splendid Farms: mismas
rutas, mismos controllers/modelos, mismas vistas — datos completamente
aislados. Pero conectar una empresa nueva a ese mecanismo hoy requiere tocar
código en 2 lugares:

1. `routes/api.php` — agregar el slug al array `foreach (['splendidfarms',
   'finca-modelo-demo'] as $empresaAgricola)`.
2. `ModuleLoader.jsx` — agregar una entrada a la constante
   `DEMO_ENTERPRISE_MIRRORS`.

Ambos cambios son mecánicos, de bajo riesgo, pero requieren un desarrollador
y un deploy. Este documento diseña cómo exponer ese mismo mecanismo como una
acción del panel de administración, sin tocar código para cada empresa
nueva — y extiende el mecanismo (hoy exclusivo de la suite agrícola) a las
otras dos suites del sistema: RH (Grupo Espléndido) y Comercio (Splendid by
Porvenir).

## Objetivo

Un administrador, desde el panel web, puede:

1. Crear una empresa nueva.
2. Elegir de qué suite es "espejo" (Agrícola / RH / Comercio / ninguna).
3. Con un clic, aprovisionar su árbol de Aplicaciones/Módulos/Submódulos.
4. La empresa queda funcionando de punta a punta (rutas + vistas + datos
   aislados) sin ningún cambio de código ni deploy.

## No-objetivos

- No se rediseña el sistema de rutas de Laravel a un esquema 100% dinámico
  sin listas de slugs (evaluado como Approach B durante el brainstorming y
  descartado por riesgo/alcance — ver decisión más abajo).
- No se resuelve en este trabajo el bug ya documentado de canales de
  WebSocket de Cosecha/Empaque hardcodeados a `'splendidfarms'` (mencionado
  como comentario en `BuildsEnterpriseStructure::buildAgriculturalSuite()`)
  — issue preexistente, fuera de alcance.
- No se permiten cadenas de espejos (una empresa espejo de otra empresa
  espejo). Solo espejo de una de las 3 raíces.

## Decisión de arquitectura

Se evaluaron 2 approaches:

- **A — Modelo espejo data-driven** (elegido): columna `mirror_source_id` en
  `enterprises`, rutas de las 3 suites generalizadas al mismo patrón
  `foreach` que ya usa la suite agrícola, aprovisionamiento vía las
  funciones ya existentes en `BuildsEnterpriseStructure`, mapa de mirrors del
  frontend servido por API en vez de hardcodeado. Riesgo bajo-medio: reusa
  un patrón ya probado en producción hoy mismo.
- **B — Ruteo 100% dinámico con `{empresa}` como route param** y middleware
  de validación de suite por request, sin arrays de slugs en ningún lado.
  Más elegante a largo plazo, pero implica reescribir cómo se declaran los
  prefijos en ~1500 líneas de `routes/api.php` en las 3 suites a la vez,
  con riesgo real sobre Splendid Farms (la empresa más usada del sistema).
  Descartado por ahora — se puede reconsiderar si el sistema necesita
  escalar a decenas de empresas por suite.

## Diseño

### 1. Modelo de datos

Migración nueva sobre `enterprises`:

```php
$table->foreignId('mirror_source_id')->nullable()->constrained('enterprises')->nullOnDelete();
```

Reglas de negocio (validadas server-side en `EnterpriseController`, no solo
en el formulario):

- `mirror_source_id` debe apuntar a una empresa **raíz** (`mirror_source_id
  IS NULL` en la empresa referenciada). Se rechaza espejar una empresa que a
  su vez ya es espejo — evita cadenas.
- Las 3 raíces actuales (`splendidfarms`, `grupoesplendido`,
  `splendidbyporvenir`) no requieren ningún flag nuevo: son raíz porque
  ninguna otra fila las referencia.
- Una vez que una empresa tiene `mirror_source_id` seteado **y** ya se le
  aprovisionó la estructura (ver sección 3), no se permite cambiarlo a otra
  raíz distinta desde el formulario — evita mezclar rutas/vistas de dos
  suites en una misma empresa. Si hace falta cambiar de suite, se borra la
  empresa y se crea de nuevo.

`Enterprise` gana un scope reutilizado tanto por las rutas como por el
aprovisionamiento:

```php
public function scopeMirrorsOf($query, string $rootSlug)
{
    return $query->where('slug', $rootSlug)
        ->orWhereHas('mirrorSource', fn ($q) => $q->where('slug', $rootSlug));
}
```

y una relación:

```php
public function mirrorSource(): BelongsTo
{
    return $this->belongsTo(Enterprise::class, 'mirror_source_id');
}
```

### 2. Rutas — generalizar el patrón `foreach` a las 3 suites

Hoy solo la suite agrícola usa el loop (ver
`2026_08_23...multi-tenancy` retrofit). Se replica el mismo patrón para las
otras 2 bloques de `routes/api.php`, cada uno consultando su propia raíz:

```php
// Agrícola (ya existe — cambia únicamente la fuente del array)
foreach (Enterprise::mirrorsOf('splendidfarms')->pluck('slug') as $empresaAgricola) {
    Route::prefix($empresaAgricola)->group(function () { /* ... bloque existente ... */ });
}

// RH (nuevo)
foreach (Enterprise::mirrorsOf('grupoesplendido')->pluck('slug') as $empresaRh) {
    Route::prefix($empresaRh)->group(function () { /* ... bloque grupoesplendido existente ... */ });
}

// Comercio (nuevo)
foreach (Enterprise::mirrorsOf('splendidbyporvenir')->pluck('slug') as $empresaComercio) {
    Route::prefix($empresaComercio)->group(function () { /* ... bloque splendidbyporvenir existente ... */ });
}
```

El contenido interior de cada grupo no se reindenta (diff mínimo, mismo
criterio que el retrofit anterior). El proyecto no usa `route:cache` en
ningún script de deploy, así que las rutas se re-evalúan en cada boot de la
aplicación — una empresa agregada a la tabla queda con rutas funcionando sin
tocar código ni redeployar.

**Estado de aislamiento de datos por suite (auditado en esta sesión):**

| Suite | Mecanismo de aislamiento actual | Trabajo pendiente |
|---|---|---|
| Agrícola (`splendidfarms`) | Trait `BelongsToEnterprise` + middleware `ResolveCurrentEnterprise` (ya retrofitteado, 176/176 tests) | Ninguno |
| RH (`grupoesplendido`) | Ya filtra por `enterprise_id` explícito recibido en cada request (`$request->validate(['enterprise_id' => 'required|exists:enterprises,id'])`), columna ya existe en sus tablas | Auditar que ningún controller de RH asuma implícitamente que la única empresa posible es Grupo Espléndido (ver Task de audit en el plan) |
| Comercio (`splendidbyporvenir`) | Administración e Inventario reutilizan literalmente los controllers de Splendid Farms — ya heredan el trait del retrofit agrícola. El único controller propio activo (`Sales\SsccLabelController`) ya resuelve la empresa vía header `X-Enterprise-Slug` (`resolveEnterpriseId()`) — backend ya aislado. `Exports`/`Purchases` no tienen ningún controller registrado todavía (rutas vacías) | Ninguno en el backend. Corregir el hardcode de `"splendidbyporvenir"` en el hook `useSsccLabels.js` del frontend (mismo patrón que los ~78 archivos del retrofit agrícola) |

### 3. Aprovisionamiento de estructura — reutiliza las funciones existentes

`BuildsEnterpriseStructure` (`database/seeders/Concerns/BuildsEnterpriseStructure.php`)
ya tiene 3 funciones fijas (`buildAgriculturalSuite`, `buildCorporateRhSuite`,
`buildTradeSuite`) que reciben cualquier `Enterprise` y le construyen su
árbol completo de Aplicaciones/Módulos/Submódulos vía `firstOrCreate`/
`updateOrCreate` — ya idempotentes, ya usadas en producción tanto para
empresas reales como demo. No hace falta clonar dinámicamente el árbol de
una empresa origen: son plantillas fijas por perfil de negocio.

**Cambio necesario**: hoy el trait asume `$this->command->info(...)` (API de
`Illuminate\Console\Command`), solo disponible dentro de un Seeder corrido
por Artisan. Se extrae la lógica (sin las líneas de logging de consola) a un
servicio de aplicación nuevo:

`app/Services/EnterpriseProvisioningService.php`

```php
class EnterpriseProvisioningService
{
    public function provision(Enterprise $enterprise): void
    {
        $root = $enterprise->mirrorSource;
        if (! $root) {
            throw new \InvalidArgumentException('La empresa no tiene una suite asignada (mirror_source_id vacío).');
        }

        match ($root->slug) {
            'splendidfarms' => $this->buildAgriculturalSuite($enterprise),
            'grupoesplendido' => $this->buildCorporateRhSuite($enterprise),
            'splendidbyporvenir' => $this->buildTradeSuite($enterprise),
            default => throw new \InvalidArgumentException("Suite raíz desconocida: {$root->slug}"),
        };
    }

    // Cuerpo idéntico al del trait actual, sin las llamadas $this->command->info().
    private function buildAgriculturalSuite(Enterprise $enterprise): void { /* ... */ }
    private function buildCorporateRhSuite(Enterprise $enterprise): void { /* ... */ }
    private function buildTradeSuite(Enterprise $enterprise): void { /* ... */ }
}
```

`BuildsEnterpriseStructure` (el trait usado por los Seeders) pasa a delegar
en este servicio para no duplicar el árbol en dos lugares:

```php
protected function buildAgriculturalSuite(Enterprise $enterprise): void
{
    app(EnterpriseProvisioningService::class)->provisionAgricultural($enterprise);
    $this->command->info(...); // logging de consola se queda en el Seeder
}
```

**Endpoint nuevo:**

```
POST /api/admin/enterprises/{enterprise}/provision-suite
```

- Solo accesible por rol admin (mismo guard que el resto de `Route::prefix('admin')`).
- Llama a `EnterpriseProvisioningService::provision($enterprise)`.
- Responde con un resumen: cuántas aplicaciones/módulos/submódulos se
  crearon vs. ya existían (útil para distinguir "primera vez" de
  "re-sincronizar").
- Reintentable sin efectos secundarios (idempotente).

### 4. Frontend — el mapa de mirrors deja de ser una constante fija

`workspace.enterprise` (poblado por `WorkspaceContext.jsx` desde el endpoint
de permisos jerárquicos) ya es el objeto que `ModuleLoader.jsx` consulta
para resolver la empresa actual.

- **Backend**: el payload de empresas (`EnterpriseController@index` y el
  endpoint de jerarquía) agrega un campo `mirror_source_slug` (o `null`).
- **Frontend**: `fetchAPI()` ya convierte automáticamente snake_case→
  camelCase, así que llega como `enterprise.mirrorSourceSlug` sin tocar la
  capa de servicios.
- **`ModuleLoader.jsx`**: se elimina la constante `DEMO_ENTERPRISE_MIRRORS`
  (líneas 780-784 actuales). `resolveRegisteredKey` pasa a recibir el slug
  espejo como parámetro en vez de mirarlo en un objeto estático:

```js
// Antes
const mirror = DEMO_ENTERPRISE_MIRRORS[enterpriseSlug];

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

`mirrorSourceSlug` se pasa en los 3 call sites existentes vía
`workspace.enterprise?.mirrorSourceSlug`. Se mantiene la misma restricción
de linter ya resuelta en el retrofit anterior: la clave resuelta es siempre
un string, el acceso real a `REGISTERED_MODULES[...]` queda inline en cada
call site (no detrás de una función), para no disparar
`react-hooks/static-components`.

**Efecto neto**: agregar una empresa nueva desde el admin panel la deja
funcionando en el frontend sin tocar `ModuleLoader.jsx` nunca más.

### 5. Panel de administración

`EnterpriseFormModal.jsx` (`src/components/admin/`) — nuevo bloque junto a
los campos existentes:

- **Selector "Suite / Empresa a espejar"** (`SearchableSelect`, estándar del
  proyecto): opciones = "Ninguna (empresa independiente)" + las 3 raíces
  (`splendidfarms`, `grupoesplendido`, `splendidbyporvenir`). Solo aparecen
  empresas con `mirror_source_id === null` (evita cadenas).
- Si la empresa ya fue guardada **y** tiene `mirror_source_id` seteado,
  aparece un botón **"Aprovisionar suite"** → `POST
  /api/admin/enterprises/{id}/provision-suite`. Loading state mientras
  corre; al terminar, `useAlert().success(...)` con el resumen devuelto por
  el endpoint (patrón obligatorio del proyecto, nunca `alert()`).
- Si ya se aprovisionó una vez, el botón cambia a **"Re-sincronizar
  estructura"** (mismo endpoint, idempotente) — útil si se agrega un
  submódulo nuevo a una raíz y hay que propagarlo a sus espejos.
- El selector de suite se deshabilita (solo lectura) una vez aprovisionada,
  reflejando la regla de negocio de la sección 1.
- `Enterprises.jsx` (listado) gana un badge chico mostrando de qué suite es
  espejo cada empresa (o "Independiente") — solo visual, sin lógica nueva.

No se modifica `Applications.jsx` / `Modules.jsx` / `Submodules.jsx` — siguen
disponibles para edición manual fina si hace falta, pero el flujo normal
para una empresa de suite conocida ya no los necesita.

### 6. Migración del mecanismo actual (Finca Modelo, Agroverde, Exportadora del Valle)

Las 3 empresas demo ya conectadas al mecanismo viejo (array fijo en
`routes/api.php` + `DEMO_ENTERPRISE_MIRRORS` hardcodeado en el frontend) se
migran al nuevo mecanismo como parte de este mismo trabajo, para no dejar
dos sistemas paralelos conviviendo:

- Cada una recibe su `mirror_source_id` correspondiente vía migración de
  datos (`finca-modelo-demo` → `splendidfarms`, `agroverde-demo` →
  `grupoesplendido`, `exportadora-valle-demo` → `splendidbyporvenir`).
- El array fijo `foreach (['splendidfarms', 'finca-modelo-demo'] as
  $empresaAgricola)` se reemplaza por el `Enterprise::mirrorsOf(...)`
  data-driven.
- La constante `DEMO_ENTERPRISE_MIRRORS` se elimina del frontend.

## Testing

- **Backend**: tests de feature para `provision-suite` — empresa nueva sin
  estructura → llamar al endpoint → verificar árbol completo creado;
  llamarlo dos veces → verificar que no duplica nada. Tests de rutas
  dinámicas: crear una empresa con `mirror_source_id` apuntando a cada una
  de las 3 raíces → `php artisan route:list --path={slug}` debe reflejar
  las mismas rutas que su raíz.
- **Auditoría de aislamiento previa al rollout de RH y Comercio**: mismo
  tipo de prueba end-to-end que se hizo para la suite agrícola en el
  retrofit anterior — crear un registro vía una empresa espejo y confirmar
  que la raíz no lo ve. Esto es una tarea explícita del plan de
  implementación, no un supuesto de diseño.
- **Frontend**: verificación manual en navegador de que una empresa espejo
  de cada una de las 3 suites carga sus vistas sin depender de la constante
  hardcodeada eliminada.

## Rollout

1. Suite Agrícola primero (ya production-tested con Finca Modelo) —
   incluye la migración de datos de la sección 6.
2. RH — habilitar tras el audit de la tabla de la sección 2 (bajo riesgo,
   los datos ya están aislados).
3. Comercio — habilitar tras corregir el único hardcode encontrado
   (`useSsccLabels.js`, frontend); el backend ya está aislado.

## Riesgos conocidos

- El bug preexistente de canales WebSocket de Cosecha/Empaque hardcodeados
  a `'splendidfarms'` (documentado como comentario en
  `BuildsEnterpriseStructure::buildAgriculturalSuite()`) sigue sin
  resolverse — cualquier empresa espejo de la suite agrícola no recibirá
  push en tiempo real en esos 2 módulos específicos, aunque los datos se
  guarden/carguen bien por REST normal.
- Si en el futuro se necesita escalar a decenas de empresas por suite, el
  approach B (ruteo 100% dinámico) evaluado y descartado en este documento
  sería la evolución natural — no hace falta resolverlo ahora.
