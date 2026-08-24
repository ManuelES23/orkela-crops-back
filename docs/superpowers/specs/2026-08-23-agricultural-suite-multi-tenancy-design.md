# Retrofit de multi-tenancy en la suite agrícola — Design Doc

**Fecha:** 2026-08-23
**Autor:** Sesión de Claude Code (auditoría solicitada por el usuario)
**Estado:** Propuesta — sin implementar

## Contexto

El frontend define 3 empresas demo (`DemoStructureSeeder.php`) como clones estructurales exactos de las 3 empresas reales — mismo `buildXxxSuite()`, mismos slugs de aplicación/módulo/submódulo:

| Empresa demo | Empresa real espejo | Suite |
|---|---|---|
| `finca-modelo-demo` | `splendidfarms` | Agrícola completa (Administración + Inventario + Contabilidad + Operación Agrícola) |
| `agroverde-demo` | `grupoesplendido` | Corporativo/RH |
| `exportadora-valle-demo` | `splendidbyporvenir` | Comercio/Exportación |

Esta sesión encontró y corrigió un bug real de aislamiento de tenant en el **frontend**: ~78 hooks/vistas bajo `src/hooks/splendidfarms/**` y `src/views/splendidfarms/**` hardcodeaban el string `"splendidfarms"` en las URLs de la API en vez de resolver la empresa actual dinámicamente (`getCurrentEnterpriseSlug()`). Ese fix ya está commiteado y verificado (`npm run build`/`npm run lint` limpios, sin errores nuevos).

Al intentar verificar que Finca Modelo mostrara las vistas reales de Splendid Farms (agregando `finca-modelo-demo` como alias de `splendidfarms` en `ModuleLoader.jsx` del frontend), la app dio 404 en `api/finca-modelo-demo/operacion-agricola/temporadas`. Investigando la causa se encontró que **el problema es de fondo, en el backend, y es mucho más grande que un string hardcodeado**.

## El hallazgo

### 1. Las rutas no son dinámicas — están duplicadas literalmente por empresa

En `routes/api.php`, cada empresa real tiene su propio bloque de rutas con el slug como string literal, no un parámetro de ruta:

```php
Route::prefix('splendidfarms')->group(function () {
    // 275 rutas: administración, inventario, contabilidad, operación agrícola
});
Route::prefix('splendidbyporvenir')->group(function () {
    // 75 rutas
});
Route::prefix('grupoesplendido')->group(function () {
    // 46 rutas
});
```

No existe (ni existió nunca) un `Route::prefix('{empresa}')` genérico — a pesar de que el `CLAUDE.md` del backend lo documenta como si existiera. Es documentación desactualizada/aspiracional.

### 2. Los controllers no filtran por empresa — no tienen de qué filtrar

Se revisaron `FixedAssetController`, `ZonaCultivoController` y `TemporadaOAController` (tres controllers de dominios distintos: Inventario, Administración/Agrícola, Operación Agrícola): **ninguno hace referencia a `enterprise`/`Enterprise` en ningún punto.** No es que ignoren el contexto de empresa — es que asumen que solo existe una empresa agrícola en el sistema.

### 3. La causa raíz: las tablas de negocio no tienen `enterprise_id`

Se auditaron las 227 migraciones del proyecto. De las que crean tablas de negocio de la suite agrícola (Administración, Inventario, Contabilidad, Operación Agrícola, Compras Agrícolas, Personal-SF, CRM), **73 tablas no tienen columna `enterprise_id`**, contra 17 tablas del sistema (usuarios, aplicaciones, RH corporativo, etc.) que sí la tienen. Lista completa de las 73 tablas por dominio:

| Dominio | Tablas (sin `enterprise_id`) | Cuenta |
|---|---|---|
| Agrícola (Administración + Operación Agrícola) | cultivos, ciclos_agricolas, temporadas, temporada_productor, temporada_zona_cultivo, temporada_lote, variedades, tipos_variedad, productores, zonas_cultivo, lotes, cultivo_productor, calibres, etapas, etapas_fenologicas, plagas, costeos_agricolas, diagnosticos_ia, tipos_carga, entity_cultivo, productos_aplicacion, aplicaciones, aplicaciones_detalle | 23 |
| Organización | entity_types, entities, areas, department_area | 4 (⚠️ `entities` ya tiene acceso cruzado vía la pivot `enterprise_entity`, que sí tiene `enterprise_id` — revisar si ese mecanismo alcanza o hay que reconciliarlo) |
| Compras Agrícolas | convenios_compra, convenio_compra_precios, liquidaciones_consignacion, liquidacion_consignacion_detalles, abonos_productores | 5 |
| Inventario | inventory_categories, inventory_items, inventory_movement_types, suppliers, purchase_orders, purchase_receipts, brands_and_update_products (productos/marcas), recipe_calibres, recipe_calibre_plus, asset_categories, fixed_assets, asset_characteristic_definitions, fixed_asset_characteristics, tipos_cajas | 14 |
| Contabilidad | accounts_payable | 1 |
| Personal (SF) | sf_employee_contracts, sf_attendance_records, sf_position_assignments, sf_employee_face_templates, sf_field_checks, attendance_records | 6 |
| Cosecha | salidas_campo_cosecha, cierres_cosecha, ventas_cosecha, calidad_cosecha | 4 |
| Empaque | pre_embarques_empaque, produccion_empaque_detalles, ajuste_peso_rezaga (auditoría no exhaustiva — Empaque tiene más submódulos que probablemente tocan más tablas no capturadas por el patrón `create_*_table` reciente) | 3+ |
| CRM | crm_regiones, crm_zonas, crm_bodegas, crm_vendedores, crm_prospectos, crm_clientes, crm_empresas_externas, crm_contactos, crm_productos, crm_actividades, crm_oportunidades, crm_oportunidad_productos, crm_presupuestos, crm_agenda | 14 (⚠️ el `CLAUDE.md` del frontend dice que CRM resuelve empresa vía header `X-Enterprise-Id` en tests, distinto al resto — revisar ese mecanismo antes de asumir que necesita el mismo tratamiento) |

**Nota de alcance:** esta tabla es producto de una auditoría por nombre de migración + `grep`, no de leer cada controller uno por uno. Antes de planificar el detalle fino de una fase, hay que confirmar contra el controller real de esa fase.

### ¿Por qué esto nunca se notó?

Porque hasta ahora solo existía una empresa real por perfil de negocio (una sola empresa "Agrícola completa", una sola "Corporativo/RH", una sola "Comercio"). El diseño de tabla única + ruta hardcodeada funcionaba porque nunca hubo un segundo inquilino real compitiendo por los mismos datos. Las empresas demo son las primeras "segundas empresas" del mismo perfil, y por eso son las que revelan el hueco.

## Qué NO es esto

- **No es un bug en el sentido de "algo se rompió".** El sistema funciona correctamente para Splendid Farms, Splendid by Porvenir y Grupo Espléndido — sus datos reales no están en riesgo por esto.
- **No es del mismo tamaño que el fix de frontend de hoy.** Ese fue reemplazar un string por una función en ~78 archivos, mecánico y de bajo riesgo. Esto es agregar una dimensión nueva (empresa) al modelo de datos de 73 tablas, con datos reales ya en producción que hay que retro-poblar sin corromper.

## Objetivo propuesto

Que cualquier empresa (incluida una demo futura) pueda usar la suite agrícola con sus propios datos, completamente aislados de las demás — sin tocar ni un registro de las empresas reales existentes en el proceso.

## No-objetivos (fuera de este documento)

- No se decide todavía si vale la pena hacer este trabajo — es una estimación de alcance para que el usuario decida.
- No cubre en detalle CRM ni Empaque (marcados como huecos de auditoría arriba) — necesitan una pasada dedicada antes de planificarse.
- No propone tocar el frontend — ya quedó listo para consumir esto dinámicamente en cuanto el backend lo soporte (ver `ModuleLoader.jsx` → `DEMO_ENTERPRISE_MIRRORS`, que ya está esperando esto).

## Enfoque propuesto: piloto en un dominio chico antes de escalar

Dado el tamaño (73 tablas, 73 controllers candidatos, 275 rutas solo para Splendid Farms), **no conviene un solo proyecto que toque las 73 tablas de una.** Se propone:

**Fase 0 — Piloto de patrón (1 tabla, 1 dominio, más chico posible):** Contabilidad → `accounts_payable` (1 tabla, 1 controller `AccountPayableController`, ~15 rutas). Sirve para validar de punta a punta:
1. Migración que agrega `enterprise_id` (nullable al inicio, para no romper filas existentes).
2. Backfill: todo lo existente en `accounts_payable` se marca con el `id` de la empresa real `splendidfarms` (o la que corresponda — Contabilidad hoy solo vive bajo Splendid Farms).
3. Migración de seguimiento que vuelve la columna `NOT NULL` una vez backfillada.
4. Controller actualizado para resolver la empresa actual (mismo patrón que "auditoría, no autorización" que ya usan los headers `X-Enterprise-Slug` — pero acá sí determina el filtro real de la query, no solo logging) y filtrar `where('enterprise_id', $empresaActual->id)` en cada query.
5. Ruta: envolver el `Route::prefix('splendidfarms')->group(...)` de Contabilidad en un loop sobre las empresas que deben tener acceso (`['splendidfarms', 'finca-modelo-demo']`), reutilizando el mismo grupo de rutas — sin reescribir cada ruta individual.
6. Verificación: Finca Modelo puede crear/editar/borrar sus propios documentos de Cuentas por Pagar sin ver ni afectar los de Splendid Farms, y viceversa.

**Fases siguientes (una por dominio, mismo patrón ya validado):** Personal (SF) → Cosecha → Compras Agrícolas → Organización (resolver primero la duda de `entities`/`enterprise_entity`) → Agrícola (el dominio más grande, 23 tablas) → Inventario → Empaque (auditar primero) → CRM (auditar primero el mecanismo de header que ya tiene).

Cada fase es un proyecto separado con su propio plan de implementación (`docs/superpowers/plans/`), no todas caben en un solo plan.

## Riesgos

- **Backfill sobre base real:** cualquier migración que toque las 73 tablas corre contra la base de datos real de Splendid Farms/Splendid by Porvenir/Grupo Espléndido. Cada fase necesita backup antes de migrar y verificación después (`php artisan migrate --pretend` primero, nunca `migrate:fresh`).
- **`entities`/`enterprise_entity`:** ya existe un mecanismo de acceso cruzado entre empresas para esta tabla — hay que entender si compite o complementa con agregar `enterprise_id` directo, antes de tocarla.
- **CRM:** el CLAUDE.md del frontend sugiere que ya resuelve empresa por header en vez de por URL — puede que necesite un patrón distinto al resto, no asumir que es igual sin confirmarlo primero.
- **Empaque:** esta auditoría no fue exhaustiva ahí — antes de planificar esa fase, repetir el barrido de migraciones/controllers específicamente para ese dominio.
- **Regla del CLAUDE.md del backend:** nunca correr `php artisan test`/`migrate` con `--env=...` — ya causó un incidente real de pérdida de datos.

## Siguiente paso

Si se decide seguir adelante, el siguiente documento a escribir es el plan de implementación de la **Fase 0** (`docs/superpowers/plans/YYYY-MM-DD-accounts-payable-multi-tenancy-pilot.md`), con tareas concretas paso a paso siguiendo el flujo de `superpowers:writing-plans`.
