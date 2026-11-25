<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesPipeline extends Model
{
    protected $fillable = ['department_id', 'name', 'stages'];

    protected $casts = ['stages' => 'array'];
}
