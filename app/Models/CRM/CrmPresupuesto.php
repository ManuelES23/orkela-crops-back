<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmPresupuesto extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_presupuestos';

    protected $fillable = [
        'empresa_id',
        'vendedor_id',
        'mes',
        'anio',
        'meta_monto',
        'meta_clientes',
        'meta_actividades',
    ];

    protected $casts = [
        'mes'              => 'integer',
        'anio'             => 'integer',
        'meta_monto'       => 'decimal:2',
        'meta_clientes'    => 'integer',
        'meta_actividades' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'vendedor_id');
    }
}
