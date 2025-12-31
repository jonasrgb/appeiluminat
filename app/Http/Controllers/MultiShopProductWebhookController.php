<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyWebhook;
use App\Models\Shop;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\ProductWebhookPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MultiShopProductWebhookController extends Controller
{
    /**
     * Handle Shopify product webhooks (create/update) coming from any shop.
     * The VerifyShopifyWebhook middleware already checked HMAC + topic.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $topic      = (string) $request->header('X-Shopify-Topic', '');
        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain', '');
        $webhookId  = (string) $request->header('X-Shopify-Webhook-Id', '');
        $payload    = $request->json()->all();

        $shopDomainKey = strtolower($shopDomain);
        $knownShops    = $this->shopSecrets();

        if ($shopDomain === '' || $topic === '' || empty($payload)) {
            Log::warning('Shopify webhook missing required headers/body', [
                'topic' => $topic,
                'shop'  => $shopDomain,
            ]);
            return response()->json(['status' => 'ignored'], 422);
        }

        if (!array_key_exists($shopDomainKey, $knownShops)) {
            Log::warning('Shopify webhook rejected (unknown shop)', [
                'topic' => $topic,
                'shop'  => $shopDomain,
            ]);
            return response()->json(['status' => 'unknown_shop'], 403);
        }

        Log::info('Shopify webhook payload dump', [
            'shop' => $shopDomain,
            'topic'=> $topic,
            'payload' => $payload,
        ]);

        $event = ShopifyWebhookEvent::firstOrCreate(
            ['webhook_id' => $webhookId ?: (string) Str::uuid()],
            [
                'topic'       => $topic,
                'shop_domain' => $shopDomain,
                'payload'     => $payload,
            ]
        );

        if (!$event->wasRecentlyCreated && $webhookId !== '') {
            Log::info('Shopify webhook duplicate ignored', [
                'topic' => $topic,
                'shop'  => $shopDomain,
                'webhook_id' => $webhookId,
            ]);
            return response()->json(['status' => 'duplicate']);
        }

        $shopModel = Shop::whereRaw('LOWER(domain) = ?', [strtolower($shopDomain)])->first();
        if (!$shopModel || !$shopModel->is_source) {
            ProductWebhookPipeline::dispatchImages(
                shopDomain: $shopDomain,
                payload: $payload,
                delaySeconds: 30,
                queue: 'webhooks'
            );
        }

        Log::info('Shopify product webhook queued', [
            'topic' => $topic,
            'shop'  => $shopDomain,
            'webhook_id' => $event->webhook_id,
        ]);

        ProcessShopifyWebhook::dispatch(
            topic: $topic,
            shopDomain: $shopDomain,
            payload: $payload,
            eventId: $event->id
        )->onQueue('webhooks');

        return response()->json(['status' => 'queued']);
    }

    /**
     * Map each allowed shop domain to its webhook secret from .env.
     *
     * @return array<string, string>
     */
    private function shopSecrets(): array
    {
        return array_filter([
            'eiluminat.myshopify.com'    => env('SHOPIFY_WEBHOOK_SECRET_EILUMINAT_BKP'),
            'lustreled.myshopify.com'    => env('SHOPIFY_WEBHOOK_SECRET_LUSTRELED_BKP'),
            'powerleds-ro.myshopify.com' => env('SHOPIFY_WEBHOOK_SECRET_POWERLED_BKP'),
        ]);
    }

}
