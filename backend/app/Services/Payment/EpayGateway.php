<?php

namespace App\Services\Payment;

use App\Models\Order;
use RuntimeException;

class EpayGateway implements PaymentGateway
{
    public function createPayment(Order $order)
    {
        $this->assertConfiguration();
        $params = [
            'pid' => config('services.epay.pid'),
            'type' => 'alipay',
            'out_trade_no' => $order->order_no,
            'notify_url' => config('services.epay.notify_url'),
            'return_url' => config('services.epay.return_url'),
            'name' => $order->plan_snapshot['name'],
            'money' => number_format($order->amount_cents / 100, 2, '.', ''),
            'currency' => $order->currency,
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';

        return rtrim(config('services.epay.base_url'), '/').'/submit.php?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function verifyNotify($rawBody, array $payload)
    {
        if (!config('services.epay.key') || !config('services.epay.pid')) {
            return false;
        }
        if (strlen($rawBody) > 16384) {
            return false;
        }
        if (!isset($payload['sign'], $payload['pid'], $payload['trade_status'])) {
            return false;
        }
        $provided = strtolower((string) $payload['sign']);
        $unsigned = $payload;
        unset($unsigned['sign'], $unsigned['sign_type']);

        return hash_equals($this->sign($unsigned), $provided)
            && hash_equals((string) config('services.epay.pid'), (string) $payload['pid'])
            && in_array($payload['trade_status'], ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);
    }

    private function sign(array $params)
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, function ($value) { return $value !== '' && $value !== null; });
        ksort($params);
        return md5(urldecode(http_build_query($params)).config('services.epay.key'));
    }

    private function assertConfiguration()
    {
        $url = (string) config('services.epay.base_url');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST)) {
            throw new RuntimeException('EPay 地址必须是有效 HTTPS URL');
        }
        if (!config('services.epay.pid') || !config('services.epay.key')) {
            throw new RuntimeException('EPay 商户配置不完整');
        }
    }
}
