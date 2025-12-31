<?php

namespace App\Jobs;

use App\Models\ProductMediaProcess;
use App\Models\Shop;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LogShopifyProductImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $shopDomain,
        public string $productGid,
        public int $attempt = 1,
        public int $maxAttempts = 3,
        public int $productId = 0,
        public string $handle = 'product',
        public string $title = '',
        public bool $shouldWatermark = false
    ) {}

    public function handle(): void
    {
        $shop = Shop::whereRaw('LOWER(domain) = ?', [strtolower($this->shopDomain)])->first();
        if (!$shop || empty($shop->access_token)) {
            Log::warning('Product images log skipped (missing shop credentials)', [
                'shop' => $this->shopDomain,
                'product_gid' => $this->productGid,
            ]);
            return;
        }

        $version  = $shop->api_version ?: '2025-01';
        $endpoint = "https://{$shop->domain}/admin/api/{$version}/graphql.json";
        $query = <<<'GQL'
        query ProductImages($id: ID!) {
          product(id: $id) {
            images(first: 250) {
              nodes {
                id
                src
              }
            }
          }
        }
        GQL;

        try {
            $client = new Client([
                'timeout' => 20,
                'connect_timeout' => 5,
            ]);

            $response = $client->post($endpoint, [
                'headers' => [
                    'X-Shopify-Access-Token' => $shop->access_token,
                    'Content-Type'           => 'application/json',
                ],
                'json' => [
                    'query'     => $query,
                    'variables' => ['id' => $this->productGid],
                ],
            ]);

            $status = $response->getStatusCode();
            $body   = json_decode((string) $response->getBody(), true) ?: [];

            if ($status >= 400 || isset($body['errors'])) {
                Log::error('Product images GraphQL HTTP error', [
                    'shop'   => $this->shopDomain,
                    'product_gid' => $this->productGid,
                    'status' => $status,
                    'errors' => $body['errors'] ?? null,
                    'body'   => $body,
                ]);
                return;
            }

            $nodes = $body['data']['product']['images']['nodes'] ?? [];

            if (empty($nodes) && $this->attempt < $this->maxAttempts) {
                self::dispatch(
                    $this->shopDomain,
                    $this->productGid,
                    $this->attempt + 1,
                    $this->maxAttempts,
                    $this->productId,
                    $this->handle,
                    $this->title,
                    $this->shouldWatermark
                )->delay(now()->addSeconds(60))
                 ->onQueue($this->queue ?? 'webhooks');

                Log::info('Product images retry scheduled', [
                    'shop' => $this->shopDomain,
                    'product_gid' => $this->productGid,
                    'attempt' => $this->attempt,
                ]);
                return;
            }

            $images = [];
            $watermarkImages = [];
            foreach (array_values($nodes) as $index => $node) {
                $position = $index + 1;
                $src = $node['src'] ?? '';

                $images[] = [
                    'position' => $position,
                    'url'      => $src,
                ];

                $watermarkImages[] = [
                    'id'       => $node['id'] ?? null,
                    'src'      => $src,
                    'position' => $position,
                ];
            }

            // Log::info('Product images snapshot', [
            //     'shop'       => $shop->domain,
            //     'product_gid'=> $this->productGid,
            //     'images'     => $images,
            //     'attempt'    => $this->attempt,
            // ]);

            // Log::info('Product images watermark decision', [
            //     'shop'            => $this->shopDomain,
            //     'product_id'      => $this->productId,
            //     'should_watermark'=> $this->shouldWatermark,
            //     'images_count'    => count($watermarkImages),
            // ]);

            if ($this->shouldWatermark && $this->productId > 0 && !empty($watermarkImages)) {
                $process = ProductMediaProcess::updateOrCreate(
                    [
                        'shop_domain' => $shop->domain,
                        'product_id' => $this->productId,
                    ],
                    [
                        'shop_id' => $shop->id,
                        'product_gid' => $this->productGid,
                        'status' => ProductMediaProcess::STATUS_PROCESSING,
                        'images_count' => count($watermarkImages),
                        'processed_count' => 0,
                        'last_error' => null,
                        'started_at' => now(),
                    ]
                );

                ApplyProductWatermark::dispatchSync(
                    shopDomain: $this->shopDomain,
                    productId: $this->productId,
                    handle: $this->handle,
                    title: $this->title,
                    images: $watermarkImages,
                    processId: $process->id
                );
            }
        } catch (\Throwable $e) {
            Log::error('Product images GraphQL exception', [
                'shop'   => $this->shopDomain,
                'product_gid' => $this->productGid,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
