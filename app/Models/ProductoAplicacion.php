<?php

namespace App\Models;

use App\Traits\BelongsToEnterprise;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoAplicacion extends Model
{
    use BelongsToEnterprise, HasFactory;

    protected $table = 'productos_aplicacion';

    protected $fillable = [
        'enterprise_id',
        'nombre',
        'ingrediente_activo',
        'marca',
        'tipo',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // ═══════ SCOPES ═══════

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeByTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    // ═══════ RELACIONES ═══════

    public function detalles(): HasMany
    {
        return $this->hasMany(AplicacionDetalle::class, 'producto_id');
    }
}
