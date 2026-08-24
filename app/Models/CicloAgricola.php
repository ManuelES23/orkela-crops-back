<?php

namespace App\Models;

use App\Traits\BelongsToEnterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CicloAgricola extends Model
{
    use BelongsToEnterprise, HasFactory, SoftDeletes, Loggable;

    protected $table = 'ciclos_agricolas';

    protected $fillable = [
        'enterprise_id',
        'cultivo_id',
        'periodo',
        'nombre',
        'año',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'user_id',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Relación con el cultivo
     */
    public function cultivo()
    {
        return $this->belongsTo(Cultivo::class, 'cultivo_id');
    }

    /**
     * Relación con el usuario que registró el ciclo
     */
    public function usuario()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
