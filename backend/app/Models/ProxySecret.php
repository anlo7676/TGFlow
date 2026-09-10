<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxySecret extends Model
{
    protected $guarded = [];
    protected $hidden = ['secret_encrypted'];
}
