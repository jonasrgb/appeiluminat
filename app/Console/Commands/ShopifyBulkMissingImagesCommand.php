<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\RunBulkMissingImagesForShop;
use App\Models\Shop;
use App\Services\Shopify\BulkMissingImagesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShopifyBulkMissingImagesCommand extends Command
{
    protected $signature = 'shopify:bulk-missing-images
        {--shop-id= : Single shop id}
        {--shop-ids= : Comma separated list of shop ids}
        {--now : Run now in foreground (for testing)}
        {--send-minicrm : Send bulk result to MiniCRM form for configured shops}
        {--timeout= : Override timeout in seconds}
        {--poll= : Override polling interval in seconds}
        {--sample= : Override sample size for logs/output}';

    protected $description = 'Run Shopify bulk operation for products without images (images_count:0)';

    public function handle(BulkMissingImagesService $service): int
    {
        $defaultIds = (array) config('shopify_bulk_missing_images.shop_ids', [2]);
        $shopIds = $this->resolveShopIds($defaultIds);

        if (empty($shopIds)) {
            $this->error('No shop ids provided.');
            return self::FAILURE;
        }

        $timeout = (int) ($this->option('timeout') ?: config('shopify_bulk_missing_images.timeout_seconds', 900));
        $poll = (int) ($this->option('poll') ?: config('shopify_bulk_missing_images.poll_seconds', 5));
        $sample = (int) ($this->option('sample') ?: config('shopify_bulk_missing_images.sample_limit', 20));

        if ($this->option('now')) {
            foreach ($shopIds as $shopId) {
                $shop = Shop::find($shopId);
                if (!$shop) {
                    $this->warn("Shop id {$shopId} not found. Skipping.");
                    continue;
                }

                $this->line("Running bulk sync now for shop {$shop->id} ({$shop->domain})...");
                $result = $service->runForShop($shop, $timeout, $poll, $sample);

                $this->info("Completed shop {$shop->id}: {$result['products_without_images_count']} products without images.");
                $this->line("Result file: storage/app/{$result['result_path']}");

                if (!empty($result['sample'])) {
                    $this->table(['title', 'link'], $result['sample']);
                }

                if ($this->option('send-minicrm')) {
                    $sendResult = $this->sendBulkResultToMiniCrm($shop, $result);
                    if ($sendResult['ok']) {
                        $this->info('MiniCRM submit OK: ' . ($sendResult['info'] ?? 'ok'));
                    } else {
                        $this->warn('MiniCRM submit skipped/failed: ' . ($sendResult['error'] ?? 'unknown'));
                    }
                }
            }

            return self::SUCCESS;
        }

        $queue = (string) config('shopify_bulk_missing_images.queue', 'bulk_ops');
        $jobs = [];

        foreach ($shopIds as $shopId) {
            $jobs[] = (new RunBulkMissingImagesForShop(
                shopId: $shopId,
                timeoutSeconds: $timeout,
                pollSeconds: $poll,
                sampleLimit: $sample,
            ))->onQueue($queue);
        }

        Bus::chain($jobs)->dispatch();

        $this->info('Dispatched chained bulk jobs for shops: ' . implode(',', $shopIds));
        $this->line('Queue: ' . $queue);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $result
     * @return array{ok:bool,info?:string,error?:string}
     */
    private function sendBulkResultToMiniCrm(Shop $shop, array $result): array
    {
        $forms = (array) config('shopify_bulk_missing_images.minicrm.forms', []);
        $shopConfig = $forms[$shop->id] ?? null;

        if (!is_array($shopConfig) || empty($shopConfig['form_hash'])) {
            return [
                'ok' => false,
                'error' => "No MiniCRM mapping configured for shop id {$shop->id}.",
            ];
        }

        $endpoint = (string) config('shopify_bulk_missing_images.minicrm.endpoint', env('MINICRM_ENDPOINT', 'https://r3.minicrm.ro/Api/Signup'));
        $signupPage = (string) config('shopify_bulk_missing_images.minicrm.signup_page', env('MINICRM_SIGNUP_PAGE', 'https://lustreled.ro/email-from-gmail'));
        $maxCommentLength = (int) config('shopify_bulk_missing_images.minicrm.max_comment_length', 2000);
        $todoField = (string) ($shopConfig['todo_comment_field'] ?? 'ToDo[3547][Comment]');
        $contactEmailField = (string) ($shopConfig['contact_email_field'] ?? 'Contact[3544][Email]');
        $contactNameField = (string) ($shopConfig['contact_name_field'] ?? 'Contact[3544][Name]');
        $shopLabel = trim((string) ($shop->name ?: $shop->domain ?: ('shop-' . $shop->id)));
        $products = $this->parseAllProductsFromStoredResult(
            (string) ($result['result_path'] ?? ''),
            (string) $shop->domain
        );
        $comments = $this->buildMiniCrmComments($shop, $result, $products, $maxCommentLength);

        $sentChunks = 0;
        $totalChunks = count($comments);

        if ($totalChunks === 0) {
            return [
                'ok' => false,
                'error' => 'No MiniCRM payload chunks generated from bulk result.',
            ];
        }

        try {
            foreach ($comments as $chunkIndex => $comment) {
                $payload = [
                    $todoField => $comment,
                    $contactEmailField => 'raport@test.com',
                    $contactNameField => $shopLabel,
                    'Dummy[]' => 1,
                    'GDPR_Contribution[]' => 1,
                    'SignupPage' => $signupPage,
                    'Referrer' => '',
                    'FormHash' => (string) $shopConfig['form_hash'],
                ];

                if (!empty($shopConfig['extra_fields']) && is_array($shopConfig['extra_fields'])) {
                    $payload = array_merge($payload, $shopConfig['extra_fields']);
                }

                $response = Http::asForm()->post($endpoint, $payload);
                $json = $response->json();

                if (!$response->successful() || !empty($json['Error'])) {
                    return [
                        'ok' => false,
                        'error' => 'Chunk ' . ($chunkIndex + 1) . '/' . $totalChunks . ': ' .
                            $this->formatMiniCrmError($response->status(), $json, $response->body()),
                    ];
                }

                $sentChunks++;
            }

            Log::info('MiniCRM bulk missing images submitted', [
                'shop_id' => $shop->id,
                'shop_domain' => $shop->domain,
                'todo_field' => $todoField,
                'products_without_images_count' => $result['products_without_images_count'] ?? null,
                'products_sent_count' => count($products),
                'chunks_sent' => $sentChunks,
            ]);

            return [
                'ok' => true,
                'info' => 'Sent ' . count($products) . ' products in ' . $sentChunks . ' MiniCRM entries.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param mixed $json
     */
    private function formatMiniCrmError(int $status, $json, string $rawBody): string
    {
        $parts = ['MiniCRM HTTP status ' . $status];

        if (is_array($json)) {
            $error = trim((string) ($json['Error'] ?? ''));
            $field = trim((string) ($json['ErrorFieldId'] ?? ''));
            $warning = trim((string) ($json['Warning'] ?? ''));
            $info = trim((string) ($json['Info'] ?? ''));

            if ($error !== '') {
                $parts[] = 'Error: ' . $error;
            }
            if ($field !== '') {
                $parts[] = 'Field: ' . $field;
            }
            if ($warning !== '') {
                $parts[] = 'Warning: ' . $warning;
            }
            if ($info !== '') {
                $parts[] = 'Info: ' . $info;
            }
        } else {
            $body = trim($rawBody);
            if ($body !== '') {
                $parts[] = 'Body: ' . mb_substr($body, 0, 300);
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,array{title:string,link:string}> $products
     * @return array<int,string>
     */
    private function buildMiniCrmComments(Shop $shop, array $result, array $products, int $maxCommentLength): array
    {
        if ($maxCommentLength < 200) {
            $maxCommentLength = 200;
        }

        $headerBase = [
            'Bulk operation report: products without images',
            'Shop: ' . $shop->domain . ' (id ' . $shop->id . ')',
            'Operation ID: ' . (string) ($result['operation_id'] ?? '-'),
            'Total products without images: ' . (string) count($products),
            'Generated at: ' . now()->toDateTimeString(),
            '',
            'Products:',
        ];

        if (empty($products)) {
            $headerBase[] = 'No products returned.';
            return [mb_substr(implode(PHP_EOL, $headerBase), 0, $maxCommentLength)];
        }

        $chunks = [];
        $currentChunk = [];
        $totalProducts = count($products);

        foreach ($products as $index => $product) {
            $line = ($index + 1) . '. ' . $product['title'] . ($product['link'] !== '' ? ' | ' . $product['link'] : '');

            $testChunk = $currentChunk;
            $testChunk[] = $line;

            $currentComment = $this->formatMiniCrmChunkComment($headerBase, $testChunk, 1, 1);
            if (mb_strlen($currentComment) <= $maxCommentLength) {
                $currentChunk[] = $line;
                continue;
            }

            if (empty($currentChunk)) {
                $currentChunk[] = mb_substr($line, 0, max(1, $maxCommentLength - 10));
            } else {
                $chunks[] = $currentChunk;
                $currentChunk = [$line];
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        $totalChunks = count($chunks);
        $comments = [];

        foreach ($chunks as $chunkIndex => $chunkLines) {
            $comment = $this->formatMiniCrmChunkComment(
                $headerBase,
                $chunkLines,
                $chunkIndex + 1,
                $totalChunks
            );

            if (mb_strlen($comment) > $maxCommentLength) {
                $comment = mb_substr($comment, 0, $maxCommentLength);
            }

            $comments[] = $comment;
        }

        // Safety: ensure at least one chunk exists even if parsing had edge cases.
        if (empty($comments)) {
            $comments[] = implode(PHP_EOL, [
                'Bulk operation report: products without images',
                'Shop: ' . $shop->domain . ' (id ' . $shop->id . ')',
                'Operation ID: ' . (string) ($result['operation_id'] ?? '-'),
                'Total products without images: ' . $totalProducts,
                'Generated at: ' . now()->toDateTimeString(),
                '',
                'No products lines generated.',
            ]);
        }

        return $comments;
    }

    /**
     * @param array<int,string> $headerBase
     * @param array<int,string> $chunkLines
     */
    private function formatMiniCrmChunkComment(array $headerBase, array $chunkLines, int $chunkIndex, int $totalChunks): string
    {
        $header = $headerBase;
        $header[] = 'Chunk: ' . $chunkIndex . '/' . $totalChunks;
        $header[] = '';

        return implode(PHP_EOL, array_merge($header, $chunkLines));
    }

    /**
     * @return array<int,array{title:string,link:string}>
     */
    private function parseAllProductsFromStoredResult(string $resultPath, string $shopDomain): array
    {
        if ($resultPath === '' || !Storage::disk('local')->exists($resultPath)) {
            return [];
        }

        $jsonl = (string) Storage::disk('local')->get($resultPath);
        $lines = preg_split('/\r\n|\r|\n/', $jsonl) ?: [];
        $products = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded) || !isset($decoded['id'], $decoded['title'])) {
                continue;
            }

            $hasMediaCount = is_array($decoded['mediaCount'] ?? null) && isset($decoded['mediaCount']['count']);
            $mediaCount = $hasMediaCount ? (int) $decoded['mediaCount']['count'] : null;
            if ($hasMediaCount && $mediaCount !== 0) {
                continue;
            }

            $products[] = [
                'title' => (string) $decoded['title'],
                'link' => $this->buildAdminProductUrl($shopDomain, (string) $decoded['id']),
            ];
        }

        return $products;
    }

    private function buildAdminProductUrl(string $shopDomain, string $productGid): string
    {
        $storeHandle = $this->extractStoreHandle($shopDomain);
        $productId = $this->numericIdFromGid($productGid);
        if ($productId === null || $productId === '') {
            return '';
        }

        return "https://admin.shopify.com/store/{$storeHandle}/products/{$productId}";
    }

    private function extractStoreHandle(string $shopDomain): string
    {
        if (str_ends_with($shopDomain, '.myshopify.com')) {
            return substr($shopDomain, 0, -strlen('.myshopify.com'));
        }

        $parts = explode('.', $shopDomain);
        return $parts[0] ?? $shopDomain;
    }

    private function numericIdFromGid(string $gid): ?string
    {
        $pos = strrpos($gid, '/');
        if ($pos === false) {
            return null;
        }

        $id = substr($gid, $pos + 1);
        return $id !== '' ? $id : null;
    }

    /**
     * @param  array<int, int|string>  $defaultIds
     * @return array<int, int>
     */
    private function resolveShopIds(array $defaultIds): array
    {
        $single = $this->option('shop-id');
        if ($single !== null && $single !== '') {
            return [(int) $single];
        }

        $csv = $this->option('shop-ids');
        if ($csv !== null && trim((string) $csv) !== '') {
            $parts = array_map('trim', explode(',', (string) $csv));
            $ids = array_values(array_filter(array_map('intval', $parts), fn (int $id) => $id > 0));
            return array_values(array_unique($ids));
        }

        $ids = array_values(array_filter(array_map('intval', $defaultIds), fn (int $id) => $id > 0));
        return array_values(array_unique($ids));
    }
}
