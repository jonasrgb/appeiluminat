<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use JsonException;
use UnexpectedValueException;

final class CatalogAuditJsonlParser
{
    /**
     * @return array{
     *     findings: array<int, array<string, mixed>>,
     *     missing_image_count: int,
     *     duplicate_sku_group_count: int,
     *     duplicate_sku_row_count: int,
     * }
     */
    public function parse(string $jsonl, Shop $shop): array
    {
        $products = [];
        $knownProductIds = [];
        $childParentLines = [];
        $imageParents = [];
        $variantNodesByGid = [];

        foreach (preg_split('/\r\n|\r|\n/', $jsonl) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException(
                    'Invalid Shopify catalog JSONL record at line '.($lineNumber + 1).'.',
                    0,
                    $exception,
                );
            }

            if (! is_array($record) || ! is_string($record['id'] ?? null)) {
                throw new UnexpectedValueException(
                    'Shopify catalog JSONL record is missing a valid id at line '.($lineNumber + 1).'.'
                );
            }

            $resourceType = $this->resourceType($record['id']);
            if ($resourceType === null) {
                throw new UnexpectedValueException(
                    'Shopify catalog JSONL record has an invalid resource GID at line '.($lineNumber + 1).'.'
                );
            }

            if ($resourceType === 'Product') {
                $status = $record['status'] ?? null;
                if (! is_string($status) || ! in_array($status, ['ACTIVE', 'ARCHIVED', 'DRAFT'], true)) {
                    throw new UnexpectedValueException(
                        'Shopify catalog JSONL product has an invalid status at line '.($lineNumber + 1).'.'
                    );
                }

                $knownProductIds[$record['id']] = true;
                if ($status === 'ACTIVE') {
                    $products[$record['id']] = $record;
                }

                continue;
            }

            $parentId = $record['__parentId'] ?? null;
            if (! is_string($parentId) || $this->resourceType($parentId) !== 'Product') {
                throw new UnexpectedValueException(
                    'Shopify catalog JSONL child has an invalid product parent at line '.($lineNumber + 1).'.'
                );
            }
            $childParentLines[$parentId] ??= $lineNumber + 1;

            if ($resourceType === 'ProductImage') {
                $imageParents[$parentId] = true;
            } elseif ($resourceType === 'ProductVariant') {
                $variantNodesByGid[$record['id']][] = [$parentId, $record];
            }
        }

        foreach ($childParentLines as $parentId => $lineNumber) {
            if (! isset($knownProductIds[$parentId])) {
                throw new UnexpectedValueException(
                    "Shopify catalog JSONL child at line {$lineNumber} references a product missing from the snapshot."
                );
            }
        }

        $variantsByParent = [];
        foreach ($variantNodesByGid as $nodes) {
            usort(
                $nodes,
                fn (array $left, array $right): int => strcmp(
                    $left[0].$this->canonicalRecord($left[1]),
                    $right[0].$this->canonicalRecord($right[1])
                )
            );

            [$parentId, $variant] = $nodes[0];
            $variantsByParent[$parentId][] = $variant;
        }

        ksort($products);

        $findings = [];
        foreach ($products as $productGid => $product) {
            if (! isset($imageParents[$productGid])) {
                $findings[] = $this->missingImageFinding($product, $shop);
            }
        }

        $skuGroups = [];
        foreach ($products as $productGid => $product) {
            $variants = $variantsByParent[$productGid] ?? [];
            usort(
                $variants,
                static fn (array $left, array $right): int => strcmp(
                    (string) ($left['id'] ?? ''),
                    (string) ($right['id'] ?? '')
                )
            );

            foreach ($variants as $variant) {
                $normalizedSku = $this->normalizeSku(
                    is_string($variant['sku'] ?? null) ? $variant['sku'] : null
                );
                if ($normalizedSku === null) {
                    continue;
                }

                $skuGroups[$normalizedSku][] = [$product, $variant];
            }
        }

        ksort($skuGroups);
        $duplicateSkuGroupCount = 0;
        $duplicateSkuRowCount = 0;
        foreach ($skuGroups as $normalizedSku => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $duplicateSkuGroupCount++;
            $duplicateSkuRowCount += count($rows);
            foreach ($rows as [$product, $variant]) {
                $findings[] = $this->duplicateSkuFinding($product, $variant, $normalizedSku, $shop);
            }
        }

        usort(
            $findings,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['fingerprint'],
                (string) $right['fingerprint']
            )
        );

        return [
            'findings' => $findings,
            'missing_image_count' => count(array_filter(
                $findings,
                static fn (array $finding): bool => $finding['finding_type'] === 'missing_image'
            )),
            'duplicate_sku_group_count' => $duplicateSkuGroupCount,
            'duplicate_sku_row_count' => $duplicateSkuRowCount,
        ];
    }

    private function normalizeSku(?string $sku): ?string
    {
        $trimmed = trim((string) $sku);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }

    private function resourceType(string $gid): ?string
    {
        $parts = explode('/', $gid);

        return count($parts) === 5
            && $parts[0] === 'gid:'
            && $parts[1] === ''
            && $parts[2] === 'shopify'
            && $parts[4] !== ''
            && ctype_digit($parts[4])
            ? $parts[3]
            : null;
    }

    private function canonicalRecord(array $record): string
    {
        ksort($record);

        return (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function missingImageFinding(array $product, Shop $shop): array
    {
        $productGid = (string) $product['id'];

        return [
            'finding_type' => 'missing_image',
            'fingerprint' => 'missing_image:'.hash('sha256', $productGid),
            ...$this->productFields($product),
            'variant_gid' => null,
            'variant_legacy_id' => null,
            'variant_title' => null,
            'sku' => null,
            'normalized_sku' => null,
            'shopify_admin_url' => $this->adminProductUrl($shop, $productGid),
        ];
    }

    /** @return array<string, mixed> */
    private function duplicateSkuFinding(
        array $product,
        array $variant,
        string $normalizedSku,
        Shop $shop
    ): array {
        $productGid = (string) $product['id'];
        $variantGid = (string) ($variant['id'] ?? '');

        return [
            'finding_type' => 'duplicate_sku',
            'fingerprint' => 'duplicate_sku:'.hash(
                'sha256',
                strlen($normalizedSku).":{$normalizedSku}:{$variantGid}"
            ),
            ...$this->productFields($product),
            'variant_gid' => $variantGid !== '' ? $variantGid : null,
            'variant_legacy_id' => $this->legacyId($variant['legacyResourceId'] ?? null),
            'variant_title' => $this->nullableString($variant['title'] ?? null),
            'sku' => $this->nullableString($variant['sku'] ?? null),
            'normalized_sku' => $normalizedSku,
            'shopify_admin_url' => $this->adminProductUrl($shop, $productGid),
        ];
    }

    /** @return array<string, mixed> */
    private function productFields(array $product): array
    {
        return [
            'product_gid' => (string) $product['id'],
            'product_legacy_id' => $this->legacyId($product['legacyResourceId'] ?? null),
            'product_title' => $this->nullableString($product['title'] ?? null),
            'product_handle' => $this->nullableString($product['handle'] ?? null),
            'product_status' => $this->nullableString($product['status'] ?? null),
        ];
    }

    private function adminProductUrl(Shop $shop, string $productGid): ?string
    {
        $productId = substr($productGid, strrpos($productGid, '/') + 1);
        if ($productId === '') {
            return null;
        }

        $domain = (string) $shop->domain;
        $storeHandle = explode('.', $domain)[0] ?? '';

        return "https://admin.shopify.com/store/{$storeHandle}/products/{$productId}";
    }

    private function legacyId(mixed $id): ?int
    {
        return is_numeric($id) ? (int) $id : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
