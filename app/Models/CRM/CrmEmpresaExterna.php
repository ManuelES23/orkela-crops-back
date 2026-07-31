<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CrmEmpresaExterna extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_empresas_externas';

    protected $fillable = [
        'empresa_id',
        'razon_social',
        'rfc',
        'industria',
        'sitio_web',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function contactos(): MorphMany
    {
        return $this->morphMany(CrmContacto::class, 'entidad');
    }
}
