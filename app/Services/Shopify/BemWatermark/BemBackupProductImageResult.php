<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;

class BemBackupProductImageResult
{
    public function __construct(
        public readonly bool $ready,
        public readonly ?string $reason = null,
        public readonly ?Shop $backupShop = null,
        public readonly ?int $sourceProductId = null,
        public readonly ?string $sourceProductGid = null,
        public readonly array $images = []
    ) {
    }

    public static function notReady(string $reason): self
    {
        return new self(false, $reason);
    }

    public static function ready(Shop $backupShop, int $sourceProductId, string $sourceProductGid, array $images): self
    {
        return new self(true, null, $backupShop, $sourceProductId, $sourceProductGid, $images);
    }
}
