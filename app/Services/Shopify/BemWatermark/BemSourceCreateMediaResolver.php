<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;

class BemSourceCreateMediaResolver
{
    public function __construct(private readonly BemShopifyGraphqlClient $graphql)
    {
    }

    /**
     * @return array{status: 'ready'|'processing'|'empty', images: array<int, array<string, mixed>>}
     */
    public function resolve(Shop $source, string $productGid): array
    {
        $query = <<<'GQL'
        query BemSourceCreateMedia($id: ID!) {
          product(id: $id) {
            media(first: 250) {
              nodes {
                id
                mediaContentType
                status
                preview { image { url } }
                ... on MediaImage {
                  alt
                  image { url }
                }
              }
            }
          }
        }
        GQL;

        $response = $this->graphql->request($source, $query, ['id' => $productGid]);
        $nodes = array_values(array_filter(
            $response['data']['product']['media']['nodes'] ?? [],
            static fn (array $node): bool => ($node['mediaContentType'] ?? null) === 'IMAGE'
        ));

        if (empty($nodes)) {
            return ['status' => 'empty', 'images' => []];
        }

        $images = [];
        foreach ($nodes as $index => $node) {
            $status = strtoupper((string) ($node['status'] ?? ''));
            if ($status === 'FAILED') {
                throw new \RuntimeException('BEM source create media failed for '.($node['id'] ?? 'unknown'));
            }

            $url = $node['image']['url'] ?? ($node['preview']['image']['url'] ?? null);
            if ($status !== 'READY' || !$url) {
                return ['status' => 'processing', 'images' => []];
            }

            $images[] = [
                'src' => $url,
                'alt' => $node['alt'] ?? null,
                'position' => $index + 1,
                'admin_graphql_api_id' => $node['id'] ?? null,
            ];
        }

        return ['status' => 'ready', 'images' => $images];
    }
}
