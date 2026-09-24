<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\FormSiteContext;
use Closure;
use Illuminate\Http\Request;

final class ResolveFormSiteContext
{
    public function handle(Request $request, Closure $next)
    {
        $site = app(FormSiteContext::class)->resolve($request);
        $request->attributes->set('crm_site', $site);
        // Only the signed gateway context may supply the real visitor IP.
        // This runs before throttling, so visitors do not share the proxy's quota.
        if ($ip = $request->attributes->get('form_client_ip')) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        return $next($request);
    }
}
