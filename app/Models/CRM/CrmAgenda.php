<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmAgenda extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_agenda';

    protected $fillable = [
        'empresa_id',
        'vendedor_id',
        'entidad_type',
        'entidad_id',
        'tipo',
        'titulo',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'completado',
        'recordatorio_at',
    ];

    protected $casts = [
        'fecha_inicio'    => 'datetime',
        'fecha_fin'       => 'datetime',
        'completado'      => 'boolean',
        'recordatorio_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'vendedor_id');
    }

    public function entidad(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePendientes($query)
    {
        return $query->where('completado', false);
    }

    public function scopeVencidos($query)
    {
        return $query->where('completado', false)->where('fecha_fin', '<', now());
    }

    public function scopeConRecordatorioPendiente($query)
    {
        return $query->where('completado', false)
            ->whereNotNull('recordatorio_at')
            ->where('recordatorio_at', '<=', now());
    }
}
