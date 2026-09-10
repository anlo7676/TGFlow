<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = [];
    protected $hidden = ['password_hash', 'totp_secret_encrypted'];
    protected $casts = ['last_login_at' => 'datetime', 'totp_confirmed_at' => 'datetime'];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }
}
