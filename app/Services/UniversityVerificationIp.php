<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

final class UniversityVerificationIp
{
    public function resolve(Request $request): string
    {
        $header = $request->header('X-Leget-University-Context');
        if (! $header) {
            // Direct public API calls are limited by their peer, never arbitrary forwarding.
            return (string) $request->server('REMOTE_ADDR', 'unknown');
        }
        [$payload, $signature] = array_pad(explode('.', $header, 2), 2, '');
        $secret = config('forms.context_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32
            && hash_equals(hash_hmac('sha256', $payload, $secret), $signature), 403, 'Неверный контекст проверки.');
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);
        $id = $request->input('certificate_id');
        abort_unless(is_array($data) && ($data['purpose'] ?? null) === 'university-verification'
            && is_int($data['timestamp'] ?? null) && abs(time() - $data['timestamp']) <= 300
            && is_string($data['client_ip'] ?? null) && filter_var($data['client_ip'], FILTER_VALIDATE_IP)
            && is_string($id) && ($data['certificate_id'] ?? null) === strtolower(trim($id)),
            403, 'Контекст проверки истёк или не соответствует запросу.');

        return $data['client_ip'];
    }
}
