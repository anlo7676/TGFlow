<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxyServer extends Model
{
    protected $guarded = [];
    protected $hidden = ['ssh_private_key_encrypted', 'ssh_password_encrypted'];
}
