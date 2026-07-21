<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesSsccLabel extends Model
{
    use HasFactory;
    use Loggable;
    use SoftDeletes;

    protected $fillable = [
        'enterprise_id',
        'created_by_user_id',
        'source_file',
        'batch_code',
        'row_number',
        'product_code',
        'product_name',
        'lote',
        'pallet_tag',
        'grower',
        'variety',
        'boxes_count',
        'presentation',
        'pack_date',
        'product_of_country',
        'product_of_state',
        'sscc',
        'serial_reference',
        'company_prefix',
        'extension_digit',
        'status',
        'printed_at',
        'raw_data',
    ];

    protected $casts = [
        'pack_date' => 'date',
        'printed_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
