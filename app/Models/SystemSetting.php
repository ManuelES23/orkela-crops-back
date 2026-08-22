<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use Loggable;

    protected $fillable = [
        'key',
        'group',
        'label',
        'type',
        'value',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Interpreta `value` (guardado siempre como texto) según el `type`
     * declarado del setting, para devolverlo ya tipado al frontend.
     */
    public function getCastedValueAttribute(): mixed
    {
        return match ($this->type) {
            'boolean' => in_array($this->value, ['1', 'true'], true),
            'integer' => (int) $this->value,
            default => (string) $this->value,
        };
    }

    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }
}
