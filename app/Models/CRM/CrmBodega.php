<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmBodega extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_bodegas';

    protected $fillable = [
        'empresa_id',
        'zona_id',
        'nombre',
        'direccion',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(CrmZona::class, 'zona_id');
    }

    public function prospectos(): HasMany
    {
        return $this->hasMany(CrmProspecto::class, 'bodega_id');
    }
}
