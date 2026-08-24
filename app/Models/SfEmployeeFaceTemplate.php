<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// No usa BelongsToEnterprise — mismo motivo que SfFieldCheck: ya resuelve
// enterprise_id vía el "Plan 2" (UserEnterpriseAccess del usuario
// autenticado), corre desde jobs/comandos sin request HTTP.
class SfEmployeeFaceTemplate extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    /**
     * Atributos que Loggable nunca debe copiar a ActivityLog.old_values /
     * new_values. El embedding facial es dato biométrico sensible: si se
     * loguea, revocar/purgar la plantilla ya no lo elimina realmente (queda
     * una copia sin control de retención en activity_logs).
     */
    protected array $loggableExcept = ['embedding'];

    protected $fillable = [
        'enterprise_id',
        'sf_employee_id',
        'embedding',
        'photo_path',
        'model_version',
        'enrolled_by_user_id',
        'enrolled_at',
        'consent_signed_at',
        'consent_document_path',
        'status',
        'revoked_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'enrolled_at' => 'datetime',
        'consent_signed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(SfEmployee::class, 'sf_employee_id');
    }

    public function enrolledBy()
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }
}
