<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmOportunidadProducto extends Model
{
    use HasFactory;

    protected $table = 'crm_oportunidad_productos';

    public $timestamps = false;

    protected $fillable = [
        'oportunidad_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:4',
        'precio_unitario' => 'decimal:2',
    ];

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(CrmOportunidad::class, 'oportunidad_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(CrmProducto::class, 'producto_id');
    }
}
