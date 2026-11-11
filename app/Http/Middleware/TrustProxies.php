<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;

/**
 * Trusts X-Forwarded-For only when the TCP peer is this host (Next.js SSR
 * running alongside nginx on the same VPS) or an explicitly configured
 * TRUSTED_PROXIES entry. Direct mobile/API callers connect straight to nginx,
 * so their REMOTE_ADDR is never in the trusted set and any spoofed
 * X-Forwarded-For header they send is ignored.
 *
 * Only HEADER_X_FORWARDED_FOR is trusted: api.shadmanagement.co is served
 * directly by nginx+PHP-FPM over HTTPS, whereas Next.js SSR proxies browser
 * requests from shadmanagement.co. Trusting X-Forwarded-Host/Proto/Port would
 * make URL::temporarySignedRoute() emit file links pointing at the frontend
 * host instead of the API host.
 */
class TrustProxies extends BaseTrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $proxies = ['127.0.0.1', '::1'];

        $serverAddr = $request->server->get('SERVER_ADDR');
        if (is_string($serverAddr) && filter_var($serverAddr, FILTER_VALIDATE_IP)) {
            $proxies[] = $serverAddr;
        }

        $configured = (string) config('app.trusted_proxies', '');
        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $proxies[] = $entry;
            }
        }

        $this->setTrustedProxyIpAddressesToSpecificIps($request, array_values(array_unique($proxies)));
    }
}
