<?php

namespace App\Services\Proxy;

use App\Models\ProxyNode;

interface ProxyEngine
{
    public function installNode(ProxyNode $node);
    public function healthCheck(ProxyNode $node);
    public function createSecret(ProxyNode $node, $label, $secret, $quota, $expiry, $connectionLimit = null);
    public function disableSecret(ProxyNode $node, $remoteIdentifier, $stateVersion);
    public function enableSecret(ProxyNode $node, $remoteIdentifier, $stateVersion);
    public function getTraffic(ProxyNode $node, $remoteIdentifier);
}
