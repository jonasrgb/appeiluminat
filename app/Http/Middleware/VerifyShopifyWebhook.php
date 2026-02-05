<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    /**
     * @param  string  $expectedTopic  ex: "products/create" sau "products/update"
     */
    public function handle(Request $request, Closure $next, string $expectedTopic = ''): Response
    {
        $topic      = (string) $request->header('X-Shopify-Topic', '');
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain', '');

        // 0) Dacă am primit topic așteptat în parametru, validăm că se potrivește.
        if ($expectedTopic !== '' && $topic !== $expectedTopic) {
            // topic nepotrivit pe acest endpoint => 204 (ignoram politicos)
            return response('', 204);
        }

        // 1) Alege secretul în funcție de topic (sau parametrul primit)
        $isUpdate = $expectedTopic === 'products/update' || $topic === 'products/update';

        $secret = $isUpdate
            ? config('services.shopify.app_webhook_secret')            // bottom secret (Custom App)
            : config('services.shopify.notifications_webhook_secret'); // Notifications secret

        if (!$secret) {
            \Log::warning('Shopify webhook secret missing', compact('shopDomain','topic'));
            return response('Missing webhook secret', 401);
        }

        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $rawBody    = $request->getContent();
        $calculated = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        if (!$hmacHeader || !hash_equals($hmacHeader, $calculated)) {
            \Log::warning('Shopify webhook invalid HMAC', compact('topic','shopDomain'));
            return response('Invalid HMAC', 401);
        }

        // 2) Idempotency (evită procesarea dublurilor)
        if ($id = $request->header('X-Shopify-Webhook-Id')) {
            if (!Cache::add('shopify_webhook_'.$id, true, now()->addDay())) {
                return response('Duplicate webhook', 200);
            }
        }

        \Log::info('Shopify webhook verified', compact('topic','shopDomain'));
        return $next($request);
    }
}
