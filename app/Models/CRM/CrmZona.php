<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmZona extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_zonas';

    protected $fillable = [
        'empresa_id',
        'region_id',
        'nombre',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(CrmRegion::class, 'region_id');
    }

    public function bodegas(): HasMany
    {
        return $this->hasMany(CrmBodega::class, 'zona_id');
    }

    public function prospectos(): HasMany
    {
        return $this->hasMany(CrmProspecto::class, 'zona_id');
    }
}
