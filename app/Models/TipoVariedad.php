<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToEnterprise;
use App\Traits\Loggable;

class TipoVariedad extends Model
{
    use BelongsToEnterprise, SoftDeletes, Loggable;

    protected $table = 'tipos_variedad';

    protected $fillable = [
        'enterprise_id',
        'variedad_id',
        'nombre',
        'descripcion',
        'user_id',
    ];

    /**
     * Relación con Variedad
     */
    public function variedad()
    {
        return $this->belongsTo(Variedad::class, 'variedad_id');
    }

    /**
     * Relación con Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
