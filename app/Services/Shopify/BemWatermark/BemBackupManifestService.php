<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

class BemBackupManifestService
{
    public const NAMESPACE = 'prod';
    public const KEY = 'watermark_manifest';

    public function __construct(private readonly BemShopifyGraphqlClient $graphql)
    {
    }

    public function emptyManifest(array $context = []): array
    {
        return array_merge([
            'version' => 1,
            'updated_at' => now()->toIso8601String(),
            'images' => [],
            'history' => [],
        ], $context);
    }

    public function fetch(Shop $backup, string $backupProductGid): array
    {
        $query = <<<'GQL'
        query BemBackupWatermarkManifest($id: ID!) {
          product(id: $id) {
            metafield(namespace: "prod", key: "watermark_manifest") {
              value
            }
          }
        }
        GQL;

        $response = $this->graphql->request($backup, $query, ['id' => $backupProductGid]);
        $value = $response['data']['product']['metafield']['value'] ?? null;
        if (!$value) {
            return $this->emptyManifest([
                'backup_shop' => $backup->domain,
                'backup_product_gid' => $backupProductGid,
            ]);
        }

        $manifest = json_decode((string) $value, true);
        if (!is_array($manifest)) {
            Log::warning('BEM backup watermark manifest JSON invalid, using empty manifest', [
                'backup_shop' => $backup->domain,
                'backup_product_gid' => $backupProductGid,
            ]);

            return $this->emptyManifest([
                'backup_shop' => $backup->domain,
                'backup_product_gid' => $backupProductGid,
            ]);
        }

        return $this->normalize($manifest, $backup, $backupProductGid);
    }

    public function update(Shop $backup, string $backupProductGid, array $manifest): void
    {
        $manifest = $this->normalize($manifest, $backup, $backupProductGid);
        $manifest['updated_at'] = now()->toIso8601String();

        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('BEM backup watermark manifest JSON encoding failed');
        }

        $mutation = <<<'GQL'
        mutation BemSetBackupWatermarkManifest($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key type }
            userErrors { field message }
          }
        }
        GQL;

        $response = $this->graphql->request($backup, $mutation, [
            'metafields' => [[
                'ownerId' => $backupProductGid,
                'namespace' => self::NAMESPACE,
                'key' => self::KEY,
                'type' => 'json',
                'value' => $json,
            ]],
        ]);

        $errors = $response['data']['metafieldsSet']['userErrors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException('BEM backup watermark manifest userErrors: '.json_encode($errors));
        }
    }

    public function appendHistory(array $manifest, string $event, array $context = []): array
    {
        $manifest['history'] = array_values((array) ($manifest['history'] ?? []));
        $manifest['history'][] = array_merge([
            'event' => $event,
            'at' => now()->toIso8601String(),
        ], $context);

        return $manifest;
    }

    public function activeImages(array $manifest): array
    {
        return array_values(array_filter(
            (array) ($manifest['images'] ?? []),
            static fn ($image) => ($image['status'] ?? 'active') === 'active'
        ));
    }

    private function normalize(array $manifest, Shop $backup, string $backupProductGid): array
    {
        $manifest['version'] = (int) ($manifest['version'] ?? 1);
        $manifest['backup_shop'] = $manifest['backup_shop'] ?? $backup->domain;
        $manifest['backup_product_gid'] = $manifest['backup_product_gid'] ?? $backupProductGid;
        $manifest['images'] = array_values((array) ($manifest['images'] ?? []));
        $manifest['history'] = array_values((array) ($manifest['history'] ?? []));

        usort($manifest['images'], static function (array $a, array $b): int {
            return ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0));
        });

        return $manifest;
    }
}
