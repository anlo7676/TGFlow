<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];
    protected $casts = ['request_payload_redacted' => 'array', 'callback_payload_redacted' => 'array', 'paid_at' => 'datetime'];
}
