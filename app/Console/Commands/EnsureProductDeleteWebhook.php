<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemShopifyGraphqlClient;
use Illuminate\Console\Command;

class EnsureProductDeleteWebhook extends Command
{
    protected $signature = 'shopify:webhooks:ensure-product-delete
        {--shop=eiluminat.myshopify.com : Source shop domain}
        {--callback-url= : Override the delete webhook callback URL}';

    protected $description = 'Ensure the source Shopify shop has one PRODUCTS_DELETE subscription for product replication cleanup.';

    public function handle(BemShopifyGraphqlClient $graphql): int
    {
        $shop = Shop::query()
            ->where('domain', (string) $this->option('shop'))
            ->where('is_source', true)
            ->first();

        if (!$shop) {
            $this->error('Source shop not found.');
            return self::FAILURE;
        }

        $callbackUrl = (string) ($this->option('callback-url') ?: rtrim((string) config('app.url'), '/').'/api/webhooks/shopify/delete');
        $existing = $this->existingDeleteWebhook($graphql, $shop, $callbackUrl);

        if ($existing) {
            $this->info('PRODUCTS_DELETE webhook already exists: '.$existing);
            return self::SUCCESS;
        }

        $mutation = <<<'GQL'
        mutation EnsureProductDeleteWebhook($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription {
              id
              topic
              endpoint {
                __typename
                ... on WebhookHttpEndpoint {
                  callbackUrl
                }
              }
            }
            userErrors {
              field
              message
            }
          }
        }
        GQL;

        $response = $graphql->request($shop, $mutation, [
            'topic' => 'PRODUCTS_DELETE',
            'webhookSubscription' => [
                'callbackUrl' => $callbackUrl,
                'format' => 'JSON',
            ],
        ]);
        $result = $response['data']['webhookSubscriptionCreate'] ?? [];
        $errors = $result['userErrors'] ?? [];

        if (!empty($errors)) {
            $this->error('Could not create PRODUCTS_DELETE webhook: '.json_encode($errors));
            return self::FAILURE;
        }

        $this->info('PRODUCTS_DELETE webhook created: '.($result['webhookSubscription']['id'] ?? 'unknown'));
        return self::SUCCESS;
    }

    private function existingDeleteWebhook(BemShopifyGraphqlClient $graphql, Shop $shop, string $callbackUrl): ?string
    {
        $query = <<<'GQL'
        query ProductDeleteWebhooks($first: Int!) {
          webhookSubscriptions(first: $first) {
            nodes {
              id
              topic
              endpoint {
                __typename
                ... on WebhookHttpEndpoint {
                  callbackUrl
                }
              }
            }
          }
        }
        GQL;

        $response = $graphql->request($shop, $query, ['first' => 250]);

        foreach (($response['data']['webhookSubscriptions']['nodes'] ?? []) as $subscription) {
            $existingUrl = $subscription['endpoint']['callbackUrl'] ?? null;
            if (($subscription['topic'] ?? null) === 'PRODUCTS_DELETE' && $existingUrl === $callbackUrl) {
                return $subscription['id'] ?? 'existing';
            }
        }

        return null;
    }
}
