<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = [];
    protected $casts = ['plan_snapshot' => 'array', 'paid_at' => 'datetime', 'expired_at' => 'datetime'];
}
