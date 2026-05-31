<?php

namespace App\Console\Commands;

use App\Models\ProductMirror;
use App\Models\Shop;
use App\Services\Shopify\BemWatermark\BemWatermarkRollbackService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BemWatermarkRollbackCommand extends Command
{
    protected $signature = 'bem-watermark:rollback
        {product_id : Source product id, target product id, or Shopify product GID}
        {--shop= : Optional target shop domain or shop id when product_id is a target product id}
        {--dry-run : Show what would be restored without replacing images}';

    protected $description = 'Restore original product images from prod.watermarked source_url values.';

    public function handle(BemWatermarkRollbackService $rollbackService): int
    {
        $productId = (string) $this->argument('product_id');
        $shopOption = $this->option('shop');
        $dryRun = (bool) $this->option('dry-run');

        $targets = $shopOption
            ? $this->targetsFromShopOption($productId, (string) $shopOption)
            : $this->targetsFromProductMirrors($productId);

        if (empty($targets)) {
            $this->error('No rollback targets found. Pass a source product id, or pass --shop with a target product id.');
            return self::FAILURE;
        }

        $exitCode = self::SUCCESS;
        foreach ($targets as $target) {
            try {
                $result = $rollbackService->rollbackProduct(
                    $target['shop'],
                    $target['product_gid'],
                    $dryRun
                );

                $this->info(sprintf(
                    '%s %s images=%d dry_run=%s',
                    $result['target_shop'],
                    $result['product_gid'],
                    $result['images_count'],
                    $result['dry_run'] ? 'true' : 'false'
                ));
            } catch (\Throwable $e) {
                $exitCode = self::FAILURE;
                $this->error(sprintf(
                    'Rollback failed for %s %s: %s',
                    $target['shop']->domain,
                    $target['product_gid'],
                    $e->getMessage()
                ));
            }
        }

        return $exitCode;
    }

    /**
     * @return array<int, array{shop: Shop, product_gid: string}>
     */
    private function targetsFromShopOption(string $productId, string $shopOption): array
    {
        $shop = is_numeric($shopOption)
            ? Shop::find((int) $shopOption)
            : Shop::whereRaw('LOWER(domain) = ?', [strtolower($shopOption)])->first();

        if (!$shop) {
            return [];
        }

        return [[
            'shop' => $shop,
            'product_gid' => $this->productGid($productId),
        ]];
    }

    /**
     * @return array<int, array{shop: Shop, product_gid: string}>
     */
    private function targetsFromProductMirrors(string $productId): array
    {
        if (str_contains($productId, 'gid://')) {
            $legacyId = (int) Str::afterLast($productId, '/');
        } else {
            $legacyId = (int) $productId;
        }

        if (!$legacyId) {
            return [];
        }

        $backupDomain = strtolower((string) config('features.bem_watermark_sync.backup_shop_domain'));
        $mirrors = ProductMirror::where('source_product_id', $legacyId)
            ->orWhere('target_product_id', $legacyId)
            ->get();

        $targets = [];
        foreach ($mirrors as $mirror) {
            if (!$mirror->target_product_gid) {
                continue;
            }

            $shop = Shop::find($mirror->target_shop_id);
            if (!$shop || strtolower((string) $shop->domain) === $backupDomain) {
                continue;
            }

            $targets[] = [
                'shop' => $shop,
                'product_gid' => $mirror->target_product_gid,
            ];
        }

        return $targets;
    }

    private function productGid(string $productId): string
    {
        return str_contains($productId, 'gid://')
            ? $productId
            : 'gid://shopify/Product/'.$productId;
    }
}
