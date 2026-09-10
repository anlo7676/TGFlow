<?php

namespace App\Services\Payment;

use App\Models\Order;

interface PaymentGateway
{
    public function createPayment(Order $order);

    public function verifyNotify($rawBody, array $payload);
}
