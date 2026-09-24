<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FormSiteContext
{
    public function resolve(Request $request): ?object
    {
        if ($request->attributes->has('crm_site')) {
            return $request->attributes->get('crm_site');
        }
        $header = $request->header('X-Leget-Form-Context');
        if (! $header) {
            return null;
        } // Old clients are accepted into the unassigned inbox.
        [$payload, $signature] = array_pad(explode('.', $header, 2), 2, '');
        $secret = config('forms.context_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32 && hash_equals(hash_hmac('sha256', $payload, $secret), $signature), 403, 'Неверный контекст сайта.');
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);
        abort_unless(is_array($data) && isset($data['domain'], $data['timestamp']) && is_string($data['domain']) && is_numeric($data['timestamp']) && abs(time() - (int) $data['timestamp']) <= 300, 403, 'Контекст сайта истёк.');
        if (isset($data['client_ip']) && is_string($data['client_ip']) && filter_var($data['client_ip'], FILTER_VALIDATE_IP)) {
            $request->attributes->set('form_client_ip', $data['client_ip']);
        }
        $domain = strtolower(preg_replace('/^www\./i', '', $data['domain']));

        // The frontend signs its own HTTP host. The service owns domain -> license resolution.
        return DB::table('licenses')->where('domain', $domain)->first();
    }
}
