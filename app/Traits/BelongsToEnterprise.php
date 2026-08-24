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
 * Requiere que el modelo tenga columna `enterprise_id` (ver migración
 * 2026_08_23_100000_add_enterprise_id_to_agricultural_suite_tables) y la
 * agregue a su $fillable.
 */
trait BelongsToEnterprise
{
    public static function bootBelongsToEnterprise(): void
    {
        static::addGlobalScope('enterprise', function (Builder $builder) {
            $enterprise = static::resolveCurrentEnterpriseForScope();

            if ($enterprise) {
                $builder->where($builder->getModel()->getTable().'.enterprise_id', $enterprise->id);
            }
        });

        static::creating(function ($model) {
            if (! $model->enterprise_id) {
                $currentEnterprise = static::resolveCurrentEnterpriseForScope();
                if ($currentEnterprise) {
                    $model->enterprise_id = $currentEnterprise->id;
                }
            }
        });
    }

    /**
     * Empresa actual para el scope/auto-asignación. Si ResolveCurrentEnterprise
     * ya resolvió una (request HTTP real), se usa esa. Si no hay nada resuelto
     * (ej. fixtures de test o comandos de consola que crean modelos
     * directamente, sin pasar por el middleware), cae a Splendid Farms — el
     * mismo fallback que ya usa el frontend en getCurrentEnterpriseSlug(), y
     * el comportamiento que todo este código tenía antes del retrofit de
     * multi-tenancy (una sola empresa agrícola implícita).
     */
    private static function resolveCurrentEnterpriseForScope(): ?Enterprise
    {
        if (app()->bound('currentEnterprise')) {
            return app('currentEnterprise');
        }

        return Enterprise::where('slug', 'splendidfarms')->first();
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
