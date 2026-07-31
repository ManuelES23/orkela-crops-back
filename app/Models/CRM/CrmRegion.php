<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmRegion extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_regiones';

    protected $fillable = [
        'empresa_id',
        'nombre',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function zonas(): HasMany
    {
        return $this->hasMany(CrmZona::class, 'region_id');
    }

    public function prospectos(): HasMany
    {
        return $this->hasMany(CrmProspecto::class, 'region_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(CrmCliente::class, 'region_id');
    }
}
