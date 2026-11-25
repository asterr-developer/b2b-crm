<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    protected $fillable = ['department_id', 'name', 'price'];

    protected $casts = ['price' => 'decimal:2'];
}
