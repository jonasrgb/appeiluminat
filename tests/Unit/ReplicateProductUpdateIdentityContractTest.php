<?php

namespace Tests\Unit;

use App\Jobs\ReplicateProductCreateToShop;
use App\Jobs\ReplicateProductUpdateToShop;
use App\Services\Shopify\LegacyParentVariantBootstrapPolicy;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class ReplicateProductUpdateIdentityContractTest extends TestCase
{
    public function test_update_job_requires_identity_resolver_and_legacy_bootstrap_policy(): void
    {
        $handle = new ReflectionMethod(ReplicateProductUpdateToShop::class, 'handle');
        $parameters = $handle->getParameters();

        $this->assertSame(
            [
                ShopifyParentIdentityResolver::class,
                LegacyParentVariantBootstrapPolicy::class,
            ],
            array_map(fn ($parameter) => $parameter->getType()?->getName(), $parameters)
        );
    }

    public function test_legacy_bootstrap_runs_before_the_unmanaged_variant_guard(): void
    {
        $source = $this->methodSource('syncVariantsStrict');

        $bootstrap = strpos($source, '$this->bootstrapLegacyVariantIdentity(');
        $guard = strpos($source, "'incomplete_parentvariant_mapping'");

        $this->assertNotFalse($bootstrap);
        $this->assertNotFalse($guard);
        $this->assertLessThan($guard, $bootstrap);
    }

    public function test_single_legacy_variant_is_reused_without_product_set(): void
    {
        $source = $this->methodSource('bootstrapLegacyVariantIdentity');

        $this->assertStringContainsString("'attach_single'", $source);
        $this->assertStringContainsString('$this->setParentVariantMetafield(', $source);
        $this->assertStringContainsString("'legacy_bootstrap_single'", $source);
        $this->assertStringNotContainsString('productVariantsBulkDelete', $source);
    }

    public function test_legacy_structure_bootstrap_uses_product_set_without_retained_variant_gid(): void
    {
        $source = $this->methodSource('bootstrapLegacyVariantIdentity');
        $attachBranch = strpos($source, '$decision[\'action\'] === \'attach_single\'');
        $replacementBranch = strpos($source, '$decision[\'action\'] === \'replace_structure\'');
        $attachSetMetafield = strpos($source, '$this->setParentVariantMetafield(');
        $attachVerifiedState = strpos($source, '$identityResolver->targetVariantState(');
        $attachPostcondition = strpos($source, '$this->assertBootstrapIdentityState(');
        $attachReplaceMirrors = strpos($source, '$this->replaceVariantMirrorsFromVerifiedState(');
        $replacementProductSet = strpos($source, '$this->productSetVariantStructure(');
        $replacementVerifiedState = strrpos($source, '$identityResolver->targetVariantState(');
        $replacementPostcondition = strrpos($source, '$this->assertBootstrapIdentityState(');
        $replacementReplaceMirrors = strrpos($source, '$this->replaceVariantMirrorsFromVerifiedState(');

        $this->assertStringContainsString("'replace_structure'", $source);
        $this->assertStringContainsString('$this->productSetVariantStructure(', $source);
        $this->assertStringContainsString('allowCompatibleOptionStructure: true', $source);
        $this->assertStringContainsString('$this->assertBootstrapIdentityState(', $source);
        $this->assertStringContainsString('$this->replaceVariantMirrorsFromVerifiedState(', $source);
        $this->assertNotFalse($attachBranch);
        $this->assertNotFalse($replacementBranch);
        $this->assertNotFalse($attachSetMetafield);
        $this->assertNotFalse($attachVerifiedState);
        $this->assertNotFalse($attachPostcondition);
        $this->assertNotFalse($attachReplaceMirrors);
        $this->assertNotFalse($replacementProductSet);
        $this->assertNotFalse($replacementVerifiedState);
        $this->assertNotFalse($replacementPostcondition);
        $this->assertNotFalse($replacementReplaceMirrors);
        $this->assertTrue($attachBranch < $attachSetMetafield);
        $this->assertTrue($attachSetMetafield < $attachVerifiedState);
        $this->assertTrue($attachVerifiedState < $attachPostcondition);
        $this->assertTrue($attachPostcondition < $attachReplaceMirrors);
        $this->assertTrue($attachReplaceMirrors < $replacementBranch);
        $this->assertTrue($replacementBranch < $replacementProductSet);
        $this->assertTrue($replacementProductSet < $replacementVerifiedState);
        $this->assertTrue($replacementVerifiedState < $replacementPostcondition);
        $this->assertTrue($replacementPostcondition < $replacementReplaceMirrors);
    }

    public function test_product_set_can_be_explicitly_enabled_for_legacy_default_variant_bootstrap(): void
    {
        $source = $this->methodSource('productSetVariantStructure');

        $this->assertStringContainsString('bool $allowCompatibleOptionStructure = false', $source);
        $this->assertStringContainsString('!$allowCompatibleOptionStructure', $source);
    }

    public function test_bootstrap_policy_is_checked_only_when_unmanaged_variants_exist(): void
    {
        $source = $this->methodSource('bootstrapLegacyVariantIdentity');

        $this->assertStringContainsString('$decision[\'status\'] === \'not_needed\'', $source);
        $this->assertStringContainsString("'status' => 'not_needed'", $source);
    }

    public function test_legacy_bootstrap_has_no_mutable_identity_fallback_and_bg_exits_first(): void
    {
        $bootstrap = $this->methodSource('bootstrapLegacyVariantIdentity');
        $handle = $this->methodSource('handle');

        foreach (['sku', 'title', 'handle', 'options_key', 'position', 'price'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($bootstrap));
        }

        $bgExit = strpos($handle, 'eiluminat-bg.myshopify.com');
        $strictSync = strpos($handle, '$this->syncVariantsStrict(');

        $this->assertNotFalse($bgExit);
        $this->assertNotFalse($strictSync);
        $this->assertLessThan($strictSync, $bgExit);
    }

    public function test_unsafe_bootstrap_returns_before_shopify_mutations(): void
    {
        $source = $this->methodSource('bootstrapLegacyVariantIdentity');
        $unsafe = strpos($source, '$decision[\'status\'] === \'unsafe\'');
        $setMetafield = strpos($source, '$this->setParentVariantMetafield(');
        $productSet = strpos($source, '$this->productSetVariantStructure(');

        $this->assertNotFalse($unsafe);
        $this->assertNotFalse($setMetafield);
        $this->assertNotFalse($productSet);
        $this->assertTrue($unsafe < $setMetafield);
        $this->assertTrue($unsafe < $productSet);
    }

    public function test_update_job_contains_no_product_handle_or_sku_fallback_methods(): void
    {
        $source = file_get_contents(app_path('Jobs/ReplicateProductUpdateToShop.php'));

        $this->assertStringNotContainsString('searchTargetProductByHandle', $source);
        $this->assertStringNotContainsString('searchTargetProductBySkus', $source);
        $this->assertStringNotContainsString('ProductParentBackfillCandidate', $source);
    }

    public function test_update_job_contains_no_variant_identity_fallbacks(): void
    {
        $source = file_get_contents(app_path('Jobs/ReplicateProductUpdateToShop.php'));

        $this->assertStringNotContainsString("matchedBy = 'options_key'", $source);
        $this->assertStringNotContainsString("matchedBy = 'single_variant'", $source);
        $this->assertStringNotContainsString("matchedBy = 'existing_mirror_gid'", $source);
        $this->assertStringNotContainsString("->where('source_options_key'", $source);
        $this->assertStringNotContainsString('$out[$key] = $norm', $source);
        $this->assertStringNotContainsString("\$missing[\$sourceVariant['source_options_key']]", $source);
    }

    public function test_strict_option_sync_never_uses_rest_fallback(): void
    {
        $reflection = new \ReflectionMethod(
            ReplicateProductUpdateToShop::class,
            'syncStrictProductOptions'
        );
        $lines = file(app_path('Jobs/ReplicateProductUpdateToShop.php'));
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        $this->assertStringNotContainsString('productOptionsRestUpdate', $source);
        $this->assertStringContainsString('strict option sync skipped', $source);
    }

    public function test_new_variants_receive_parentvariant_atomically(): void
    {
        $reflection = new \ReflectionMethod(
            ReplicateProductUpdateToShop::class,
            'productVariantsBulkCreateForUpdate'
        );
        $lines = file(app_path('Jobs/ReplicateProductUpdateToShop.php'));
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        $this->assertStringContainsString("'metafields'", $source);
        $this->assertStringContainsString("'key' => 'parentvariant'", $source);
        $this->assertStringContainsString("'value' => (string) \$sourceId", $source);
        $this->assertStringContainsString('metafield(namespace: "custom", key: "parentvariant")', $source);
        $this->assertStringContainsString("\$node['metafield']['value']", $source);
    }

    public function test_updates_are_serialized_per_source_and_target_product(): void
    {
        $job = new ReplicateProductUpdateToShop(4, 3, 700, []);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_variant_identity_is_validated_before_product_writes(): void
    {
        $source = $this->methodSource('handle');
        $variantPosition = strpos($source, '$this->syncVariantsStrict(');
        $productPosition = strpos($source, '$this->productUpdate(');
        $imagePosition = strpos($source, '$this->syncImagesReplaceAll(');

        $this->assertNotFalse($variantPosition);
        $this->assertNotFalse($productPosition);
        $this->assertNotFalse($imagePosition);
        $this->assertLessThan($productPosition, $variantPosition);
        $this->assertLessThan($imagePosition, $variantPosition);
        $this->assertStringContainsString('if (!$this->syncVariantsStrict(', $source);
    }

    public function test_variant_sync_refuses_empty_payload_and_only_incompatible_mixed_replacement(): void
    {
        $source = $this->methodSource('syncVariantsStrict');

        $this->assertStringContainsString('empty_source_variants', $source);
        $this->assertStringNotContainsString('variant_set_replacement_requires_manual_resolution', $source);
        $this->assertStringContainsString('mixed_variant_replacement_option_structure_mismatch', $source);
        $this->assertStringContainsString('mixedReplacementHasCompatibleOptions', $source);
        $this->assertStringContainsString('): bool', $source);
    }

    public function test_mixed_replacement_requires_the_same_option_names_in_the_same_order(): void
    {
        $this->assertTrue(method_exists(
            ReplicateProductUpdateToShop::class,
            'mixedReplacementHasCompatibleOptions'
        ));

        $job = new ReplicateProductUpdateToShop(4, 3, 700, []);
        $method = new ReflectionMethod($job, 'mixedReplacementHasCompatibleOptions');

        $sourceOptions = [
            ['name' => 'Culoare test E2E', 'values' => ['Verde']],
            ['name' => 'Dimensiune test E2E', 'values' => ['Mare']],
        ];
        $matchingTargetOptions = [
            ['name' => 'culoare TEST e2e'],
            ['name' => 'DIMENSIUNE TEST E2E'],
        ];
        $reorderedTargetOptions = array_reverse($matchingTargetOptions);

        $this->assertTrue($method->invoke($job, $sourceOptions, $matchingTargetOptions));
        $this->assertFalse($method->invoke($job, $sourceOptions, $reorderedTargetOptions));
        $this->assertFalse($method->invoke($job, $sourceOptions, [
            ['name' => 'Culoare test E2E'],
        ]));
    }

    public function test_structural_variant_sync_rejects_shopify_truncated_webhook_payloads(): void
    {
        $job = new ReplicateProductUpdateToShop(4, 3, 700, []);
        $method = new ReflectionMethod($job, 'sourceVariantPayloadIsComplete');

        $this->assertTrue($method->invoke($job, ['variants' => array_fill(0, 99, ['id' => 1])]));
        $this->assertFalse($method->invoke($job, ['variants' => array_fill(0, 100, ['id' => 1])]));
    }

    public function test_different_option_structure_uses_product_set_with_only_parentvariant_identity(): void
    {
        Http::fakeSequence()->push([
            'data' => [
                'productSet' => [
                    'product' => [
                        'id' => 'gid://shopify/Product/10',
                        'variants' => ['nodes' => [
                            [
                                'id' => 'gid://shopify/ProductVariant/retained',
                                'legacyResourceId' => '501',
                                'metafield' => ['value' => '111'],
                            ],
                            [
                                'id' => 'gid://shopify/ProductVariant/created',
                                'legacyResourceId' => '502',
                                'metafield' => ['value' => '222'],
                            ],
                        ]],
                    ],
                    'userErrors' => [],
                ],
            ],
        ]);

        $job = new ReplicateProductUpdateToShop(4, 3, 10, []);
        $shop = new \App\Models\Shop([
            'domain' => 'target.myshopify.com',
            'access_token' => 'test-token',
            'api_version' => '2025-01',
        ]);
        $retainedMirror = new \App\Models\VariantMirror([
            'source_variant_id' => 111,
            'target_variant_gid' => 'gid://shopify/ProductVariant/retained',
        ]);
        $sourceVariants = [
            '111' => [
                'source_variant_id' => 111,
                'source_options_key' => 'material=metal',
                'sku' => null,
                'price' => '10.00',
                'compare_at_price' => null,
                'taxable' => true,
                'inventory_policy' => 'deny',
            ],
            '222' => [
                'source_variant_id' => 222,
                'source_options_key' => 'material=sticla',
                'sku' => '',
                'price' => '12.00',
                'compare_at_price' => null,
                'taxable' => true,
                'inventory_policy' => 'deny',
            ],
        ];

        $method = new ReflectionMethod($job, 'productSetVariantStructure');
        $result = $method->invoke(
            $job,
            $shop,
            'gid://shopify/Product/10',
            $sourceVariants,
            [['name' => 'Material', 'values' => ['Metal', 'Sticla']]],
            [['id' => 'gid://shopify/ProductOption/old', 'name' => 'Culoare']],
            ['111' => $retainedMirror]
        );

        $this->assertSame(
            ['gid://shopify/ProductVariant/retained', 'gid://shopify/ProductVariant/created'],
            [$result['111']['variant_gid'], $result['222']['variant_gid']]
        );

        $request = Http::recorded()[0][0]->data();
        $this->assertStringContainsString('productSet', $request['query']);
        $this->assertSame('gid://shopify/Product/10', $request['variables']['identifier']['id']);
        $this->assertTrue($request['variables']['synchronous']);
        $this->assertSame('Material', $request['variables']['input']['productOptions'][0]['name']);
        $this->assertSame(
            'gid://shopify/ProductVariant/retained',
            $request['variables']['input']['variants'][0]['id']
        );
        $this->assertArrayNotHasKey('metafields', $request['variables']['input']['variants'][0]);
        $this->assertArrayNotHasKey('id', $request['variables']['input']['variants'][1]);
        $this->assertSame(
            '222',
            $request['variables']['input']['variants'][1]['metafields'][0]['value']
        );
    }

    public function test_stale_variants_use_the_supported_shopify_bulk_delete_mutation(): void
    {
        $jobSource = file_get_contents(app_path('Jobs/ReplicateProductUpdateToShop.php'));
        $syncSource = $this->methodSource('syncVariantsStrict');

        $this->assertStringContainsString('$this->productVariantsBulkDelete(', $syncSource);
        $this->assertStringContainsString('productVariantsBulkDelete(', $jobSource);
        $this->assertStringNotContainsString('mutation productVariantDelete(', $jobSource);
    }

    public function test_default_title_option_collapse_runs_after_stale_variant_deletion(): void
    {
        $syncSource = $this->methodSource('syncVariantsStrict');
        $optionSource = $this->methodSource('syncStrictProductOptions');
        $collapseSource = $this->methodSource('collapseTargetOptionsToDefaultTitle');

        $deletePosition = strpos($syncSource, '$this->productVariantsBulkDelete(');
        $optionPosition = strrpos($syncSource, '$this->syncStrictProductOptions(');

        $this->assertNotFalse($deletePosition);
        $this->assertNotFalse($optionPosition);
        $this->assertLessThan($optionPosition, $deletePosition);
        $this->assertStringContainsString('collapseTargetOptionsToDefaultTitle', $optionSource);
        $this->assertStringContainsString('productOptionUpdate', $collapseSource);
        $this->assertStringNotContainsString('productOptionsSet', $optionSource);
    }

    public function test_extra_custom_option_is_deleted_after_its_stale_variant_is_removed(): void
    {
        Http::fakeSequence()
            ->push([
                'data' => [
                    'product' => [
                        'options' => [
                            [
                                'id' => 'gid://shopify/ProductOption/1',
                                'name' => 'Culoare test E2E',
                                'values' => ['Alb'],
                                'position' => 1,
                                'optionValues' => [[
                                    'id' => 'gid://shopify/ProductOptionValue/1',
                                    'name' => 'Alb',
                                    'hasVariants' => true,
                                ]],
                            ],
                            [
                                'id' => 'gid://shopify/ProductOption/2',
                                'name' => 'Dimensiune test E2E',
                                'values' => ['Mica'],
                                'position' => 2,
                                'optionValues' => [[
                                    'id' => 'gid://shopify/ProductOptionValue/2',
                                    'name' => 'Mica',
                                    'hasVariants' => true,
                                ]],
                            ],
                        ],
                    ],
                ],
            ])
            ->push([
                'data' => [
                    'productOptionsDelete' => [
                        'product' => ['id' => 'gid://shopify/Product/10'],
                        'userErrors' => [],
                    ],
                ],
            ]);

        $job = new ReplicateProductUpdateToShop(4, 3, 10, [
            'options' => [[
                'name' => 'Culoare test E2E',
                'values' => ['Alb'],
            ]],
        ]);
        $shop = new \App\Models\Shop([
            'domain' => 'target.myshopify.com',
            'access_token' => 'test-token',
            'api_version' => '2025-01',
        ]);

        $method = new ReflectionMethod($job, 'syncStrictProductOptions');
        $method->invoke($job, $shop, 'gid://shopify/Product/10');

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $deletePayload = $requests[1][0]->data();
        $this->assertStringContainsString('productOptionsDelete', $deletePayload['query']);
        $this->assertSame(
            ['gid://shopify/ProductOption/2'],
            $deletePayload['variables']['options']
        );
        $this->assertSame('POSITION', $deletePayload['variables']['strategy']);
    }

    public function test_update_graphql_uses_the_shop_configured_api_version(): void
    {
        $source = $this->methodSource('gql');

        $this->assertStringContainsString('$shop->api_version ?: \'2025-01\'', $source);
    }

    public function test_update_timeout_is_shorter_than_overlap_lock_expiry(): void
    {
        $job = new ReplicateProductUpdateToShop(4, 3, 700, []);

        $this->assertSame(840, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertStringContainsString('->expireAfter(900)', $this->methodSource('middleware'));
    }

    public function test_database_queue_retry_window_exceeds_replication_job_timeouts(): void
    {
        $create = new ReplicateProductCreateToShop(4, 3, 700, []);
        $update = new ReplicateProductUpdateToShop(4, 3, 700, []);
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan($create->timeout, $retryAfter);
        $this->assertGreaterThan($update->timeout, $retryAfter);
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(ReplicateProductUpdateToShop::class, $method);
        $lines = file(app_path('Jobs/ReplicateProductUpdateToShop.php'));

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
