<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmContacto extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_contactos';

    protected $fillable = [
        'empresa_id',
        'entidad_type',
        'entidad_id',
        'nombre',
        'cargo',
        'email',
        'telefono',
        'es_principal',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function entidad(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePrincipal($query)
    {
        return $query->where('es_principal', true);
    }
}
