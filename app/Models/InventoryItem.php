<?php

namespace App\Models;

use App\Traits\BelongsToEnterprise;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use BelongsToEnterprise;
}
