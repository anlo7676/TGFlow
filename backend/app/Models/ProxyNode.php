<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxyNode extends Model
{
    protected $guarded = [];

    public function server()
    {
        return $this->belongsTo(ProxyServer::class, 'proxy_server_id');
    }
}
