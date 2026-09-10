<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'available_at' => 'datetime', 'published_at' => 'datetime'];
}
