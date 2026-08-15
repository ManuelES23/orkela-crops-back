<?php

namespace App\Traits;

use App\Models\ActivityLog;

trait Loggable
{
    /**
     * Boot del trait
     */
    public static function bootLoggable()
    {
        // Evento al crear
        static::created(function ($model) {
            ActivityLog::log(
                action: 'create',
                model: class_basename($model),
                modelId: $model->id,
                oldValues: null,
                newValues: static::filterLoggableAttributes($model, $model->getAttributes())
            );
        });

        // Evento al actualizar
        static::updated(function ($model) {
            $dirty = $model->getDirty();
            $original = [];

            foreach (array_keys($dirty) as $key) {
                $original[$key] = $model->getOriginal($key);
            }

            ActivityLog::log(
                action: 'update',
                model: class_basename($model),
                modelId: $model->id,
                oldValues: static::filterLoggableAttributes($model, $original),
                newValues: static::filterLoggableAttributes($model, $dirty)
            );
        });

        // Evento al eliminar
        static::deleted(function ($model) {
            ActivityLog::log(
                action: 'delete',
                model: class_basename($model),
                modelId: $model->id,
                oldValues: static::filterLoggableAttributes($model, $model->getAttributes()),
                newValues: null
            );
        });
    }

    /**
     * Quita del arreglo de atributos aquellos que el modelo marcó como
     * sensibles vía `protected array $loggableExcept = [...]`, para que
     * nunca se copien a ActivityLog.old_values / new_values.
     *
     * Si el modelo no define $loggableExcept (o lo define vacío), el
     * arreglo se retorna sin modificar — comportamiento por defecto
     * idéntico al de antes de este método para cualquier modelo que no
     * opte explícitamente por la exclusión.
     */
    protected static function filterLoggableAttributes($model, ?array $attributes): ?array
    {
        if (empty($attributes)) {
            return $attributes;
        }

        $except = property_exists($model, 'loggableExcept') ? (array) $model->loggableExcept : [];

        if (empty($except)) {
            return $attributes;
        }

        return array_diff_key($attributes, array_flip($except));
    }
}
