<?php

namespace App\Http\Controllers;

use App\Services\Payment\PaymentGateway;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify(Request $request, PaymentGateway $gateway, PaymentService $payments)
    {
        $payload = $request->all();
        abort_unless($gateway->verifyNotify($request->getContent(), $payload), 400, '签名或支付状态无效');
        foreach (['out_trade_no', 'trade_no', 'money', 'currency'] as $field) {
            abort_unless(isset($payload[$field]) && is_scalar($payload[$field]), 422, '支付字段缺失');
        }
        $payments->confirm($payload);
        return response('success', 200)->header('Content-Type', 'text/plain');
    }
}
