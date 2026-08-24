<?php

namespace App\Models;

use App\Traits\BelongsToEnterprise;
use Illuminate\Database\Eloquent\Model;

class InventoryCategory extends Model
{
    use BelongsToEnterprise;

    protected $fillable = [
        'enterprise_id',
    ];
}
