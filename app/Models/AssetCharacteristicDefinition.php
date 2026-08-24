<?php

namespace App\Models;

use App\Traits\BelongsToEnterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo de características disponibles para un Tipo/Subtipo de Activo
 * (ej. Subtipo "Laptops" -> Procesador, RAM). Son sugerencias opcionales:
 * el usuario decide cuáles capturar en cada Activo Fijo.
 */
class AssetCharacteristicDefinition extends Model
{
    use BelongsToEnterprise, HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'enterprise_id',
        'category_id',
        'name',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }
}
