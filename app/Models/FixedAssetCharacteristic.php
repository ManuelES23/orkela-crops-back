<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valor de una característica capturado en un Activo Fijo específico
 * (ej. Activo "Tractor JohnDeere 03" -> Horas de uso: 1200).
 */
class FixedAssetCharacteristic extends Model
{
    use HasFactory;

    protected $fillable = [
        'fixed_asset_id',
        'definition_id',
        'name',
        'value',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AssetCharacteristicDefinition::class, 'definition_id');
    }
}
