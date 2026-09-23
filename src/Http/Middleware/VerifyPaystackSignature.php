<?php

namespace Otatechie\PaystackConnect\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook that Paystack didn't sign.
 *
 * The signature is checked against the raw request body, exactly as Paystack
 * sent it. Re-encoding parsed JSON (for example with json_encode) changes the
 * bytes, turning "/" into "\/", and makes genuine webhooks fail the check.
 */
class VerifyPaystackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = (array) config('paystack-connect.webhook.allowed_ips', []);

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            abort(403, 'Webhook source not allowed.');
        }

        $secret = (string) config('paystack-connect.secret_key');
        $signature = (string) $request->header('x-paystack-signature');

        if ($secret === '' || $signature === '') {
            abort(401, 'Missing Paystack signature.');
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid Paystack signature.');
        }

        return $next($request);
    }
}
