<?php

use App\Models\Enterprise;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrofit de multi-tenancy de la suite agrícola completa — ver
 * docs/superpowers/specs/2026-08-23-agricultural-suite-multi-tenancy-design.md.
 *
 * Agrega `enterprise_id` a las 67 tablas de negocio de Administración,
 * Inventario, Contabilidad y Operación Agrícola que hoy asumen una sola
 * empresa agrícola (Splendid Farms). Patrón por tabla, en tres pasos para
 * no romper filas existentes:
 *   1. Columna nullable.
 *   2. Backfill: todo lo que ya existe se marca como de Splendid Farms
 *      (es la única empresa real que usa esta suite hoy).
 *   3. NOT NULL, ya con todo backfilleado.
 *
 * Las tablas pivote (temporada_productor, temporada_zona_cultivo,
 * temporada_lote, cultivo_productor, department_area, entity_cultivo) NO
 * están en esta lista a propósito: heredan el aislamiento de sus tablas
 * padre (ej. Temporada) a través del global scope de esas tablas — no
 * necesitan su propia columna.
 *
 * `tipos_cajas` tampoco está: existe pero su modelo (TipoCaja) resuelve a
 * la tabla equivocada (`tipo_cajas`, sin la "s" de plural) por convención
 * de nombres — un bug preexistente y separado de este retrofit. Se deja
 * fuera de este pase; hay que arreglar el modelo antes de scopearla.
 */
return new class extends Migration
{
    private array $tables = [
        // Agrícola / Administración
        'cultivos', 'ciclos_agricolas', 'temporadas', 'variedades', 'tipos_variedad',
        'productores', 'zonas_cultivo', 'lotes', 'calibres', 'etapas', 'etapas_fenologicas',
        'plagas', 'costeos_agricolas', 'diagnosticos_ia', 'tipos_carga', 'productos_aplicacion',
        'aplicaciones', 'aplicaciones_detalle',
        // Organización
        'entity_types', 'entities', 'areas',
        // Compras Agrícolas
        'convenios_compra', 'convenio_compra_precios', 'liquidaciones_consignacion',
        'liquidacion_consignacion_detalles', 'abonos_productores',
        // Inventario
        'inventory_categories', 'inventory_items', 'inventory_movement_types', 'suppliers',
        'purchase_orders', 'purchase_receipts', 'brands', 'recipe_calibres', 'recipe_calibre_plus',
        'asset_categories', 'fixed_assets', 'asset_characteristic_definitions',
        'fixed_asset_characteristics',
        // Contabilidad
        'accounts_payable',
        // Personal (SF)
        'sf_employee_contracts', 'sf_attendance_records', 'sf_position_assignments',
        'sf_employee_face_templates', 'sf_field_checks', 'attendance_records',
        // Cosecha
        'salidas_campo_cosecha', 'cierres_cosecha', 'ventas_cosecha', 'calidad_cosecha',
        // Empaque
        'pre_embarques_empaque', 'produccion_empaque_detalles', 'ajuste_peso_rezaga',
        'calidad_empaque', 'calidad_empaque_muestras', 'calidad_empaque_muestra_plagas',
        'embarques_empaque', 'embarque_empaque_detalles', 'pre_embarque_empaque_detalles',
        'proceso_empaque', 'produccion_empaque', 'recepciones_empaque', 'rezaga_empaque',
        'venta_rezaga_empaque', 'venta_rezaga_empaque_detalles', 'requisiciones_campo',
        'requisicion_campo_detalles', 'visitas_campo', 'visita_campo_detalles',
        'visita_campo_fotos', 'visita_campo_plagas', 'visita_campo_recomendaciones',
    ];

    public function up(): void
    {
        $splendidFarms = Enterprise::where('slug', 'splendidfarms')->first();

        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (Schema::hasColumn($tableName, 'enterprise_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('enterprise_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('enterprises')
                    ->cascadeOnDelete();
            });

            if ($splendidFarms) {
                DB::table($tableName)
                    ->whereNull('enterprise_id')
                    ->update(['enterprise_id' => $splendidFarms->id]);
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('enterprise_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'enterprise_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('enterprise_id');
            });
        }
    }
};
