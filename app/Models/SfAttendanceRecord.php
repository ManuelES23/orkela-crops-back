<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// No usa BelongsToEnterprise — mismo motivo que SfFieldCheck: se consolida
// desde VerifyFieldCheckJob (job en cola, sin request HTTP) a partir del
// enterprise_id ya resuelto por el "Plan 2" en SfFieldCheck.
class SfAttendanceRecord extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'enterprise_id',
        'sf_employee_id',
        'date',
        'check_in',
        'check_out',
        'hours_worked',
        'status',
        'late_minutes',
        'source_file',
        'source_device',
        'notes',
        'imported_by_user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'hours_worked' => 'decimal:2',
        'late_minutes' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(SfEmployee::class, 'sf_employee_id');
    }

    public function importedBy()
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
