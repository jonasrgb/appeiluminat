<?php

namespace Tests\Unit;

use App\Jobs\ReplicateProductCreateToShop;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use ReflectionMethod;
use Tests\TestCase;

class ReplicateProductCreateIdentityContractTest extends TestCase
{
    public function test_delayed_source_media_has_a_bounded_extended_retry_window(): void
    {
        $job = new ReplicateProductCreateToShop(4, 3, 700, []);
        $source = $this->methodSource('hydrateDelayedBemSourceMediaBeforeCreate');

        $this->assertGreaterThanOrEqual(40, $job->tries);
        $this->assertStringContainsString("\$this->attempts() < 10", $source);
        $this->assertStringContainsString("\$this->attempts() >= \$this->tries", $source);
    }

    public function test_create_guard_uses_the_shared_strict_product_resolver(): void
    {
        $handle = new ReflectionMethod(ReplicateProductCreateToShop::class, 'handle');
        $parameters = $handle->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame(
            ShopifyParentIdentityResolver::class,
            $parameters[0]->getType()?->getName()
        );

        $source = file_get_contents(app_path('Jobs/ReplicateProductCreateToShop.php'));
        $this->assertStringNotContainsString('existingShopifyProductByParentProduct', $source);
    }

    public function test_created_product_is_persisted_before_parentproduct_write(): void
    {
        $method = $this->methodSource('handle');
        $createPosition = strpos($method, '$this->productCreate(');
        $tail = substr($method, $createPosition);

        $mirrorPosition = strpos($tail, '$this->storeExistingProductMirror(');
        $parentPosition = strpos($tail, '$this->setParentProductMetafield(');

        $this->assertNotFalse($createPosition);
        $this->assertNotFalse($mirrorPosition);
        $this->assertNotFalse($parentPosition);
        $this->assertLessThan($parentPosition, $mirrorPosition);
        $this->assertStringContainsString(
            'ProductMirror::updateOrCreate(',
            $this->methodSource('storeExistingProductMirror')
        );
    }

    public function test_parent_identity_writes_are_retryable_failures(): void
    {
        foreach (['setParentProductMetafield', 'setParentVariantMetafield'] as $method) {
            $source = $this->methodSource($method);

            $this->assertStringNotContainsString('catch (', $source, $method);
            $this->assertStringContainsString('throw new \\RuntimeException', $source, $method);
        }
    }

    public function test_created_variants_are_mapped_by_parentvariant_not_mutable_options(): void
    {
        $source = $this->methodSource('createAllOptionsAndVariants');

        $this->assertStringNotContainsString('buildOptionsKeyFromSelectedOptions', $source);
        $this->assertStringNotContainsString('$byKeySource', $source);
        $this->assertStringNotContainsString('$sourceVariants[$index]', $source);
        $this->assertStringContainsString("metafield(namespace: \"custom\", key: \"parentvariant\")", $source);
        $this->assertStringContainsString("\$node['metafield']['value']", $source);
    }

    public function test_single_variant_identity_is_written_in_its_first_bulk_update(): void
    {
        $source = $this->methodSource('updateDefaultVariant');

        $this->assertStringContainsString("'key' => 'parentvariant'", $source);
        $this->assertStringContainsString("'value' => (string) \$sourceVariantId", $source);
        $this->assertStringContainsString('source variant ID', $source);
    }

    public function test_create_establishes_variant_identity_before_media_and_field_updates(): void
    {
        $source = $this->methodSource('productCreate');
        $singlePosition = strpos($source, '$this->updateDefaultVariant(');
        $multiPosition = strpos($source, '$this->createAllOptionsAndVariants(');
        $mediaPosition = strpos($source, '$this->attachImagesWithProductUpdate(');
        $fieldsPosition = strpos($source, '$this->updateProductFields(');

        $this->assertNotFalse($singlePosition);
        $this->assertNotFalse($multiPosition);
        $this->assertNotFalse($mediaPosition);
        $this->assertNotFalse($fieldsPosition);
        $this->assertLessThan($mediaPosition, $singlePosition);
        $this->assertLessThan($mediaPosition, $multiPosition);
        $this->assertLessThan($fieldsPosition, $singlePosition);
        $this->assertLessThan($fieldsPosition, $multiPosition);
    }

    public function test_existing_strict_product_is_continued_through_update_not_silently_returned(): void
    {
        $source = $this->methodSource('handle');
        $foundBranch = substr(
            $source,
            strpos($source, "if (\$existingResolution['status'] === 'found')"),
            strpos($source, 'if ($existingMirror)') - strpos($source, "if (\$existingResolution['status'] === 'found')")
        );

        $this->assertStringContainsString('continueExistingCreate', $foundBranch);
        $this->assertStringContainsString('ReplicateProductUpdateToShop::dispatch', $foundBranch);
    }

    public function test_single_variant_deterministic_gid_is_cached_before_parentvariant_mutation(): void
    {
        $source = $this->methodSource('productCreate');
        $cachePosition = strpos($source, '$this->persistProvisionalSingleVariantMapping(');
        $updatePosition = strpos($source, '$this->updateDefaultVariant(');

        $this->assertNotFalse($cachePosition);
        $this->assertNotFalse($updatePosition);
        $this->assertLessThan($updatePosition, $cachePosition);
    }

    public function test_create_timeout_is_shorter_than_its_lock_expiry(): void
    {
        $job = new ReplicateProductCreateToShop(4, 3, 700, []);

        $this->assertSame(840, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertStringContainsString('900', $this->methodSource('handle'));
    }

    public function test_create_sets_status_atomically_and_only_active_products_are_published(): void
    {
        $create = $this->methodSource('productCreate');
        $handle = $this->methodSource('handle');

        $this->assertMatchesRegularExpression(
            "/'status'\\s*=>\\s*\\\$this->statusFromPayload\\(\\\$sourcePayload\\)/",
            $create
        );
        $this->assertStringContainsString(
            "if (\$this->statusFromPayload(\$this->payload) === 'ACTIVE')",
            $handle
        );

        $job = new ReplicateProductCreateToShop(4, 3, 700, []);
        $status = new ReflectionMethod(ReplicateProductCreateToShop::class, 'statusFromPayload');
        $status->setAccessible(true);

        $this->assertSame('DRAFT', $status->invoke($job, []));
        $this->assertSame('DRAFT', $status->invoke($job, ['status' => 'draft']));
        $this->assertSame('ACTIVE', $status->invoke($job, ['status' => 'active']));
        $this->assertSame('ARCHIVED', $status->invoke($job, ['status' => 'archived']));
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(ReplicateProductCreateToShop::class, $method);
        $lines = file(app_path('Jobs/ReplicateProductCreateToShop.php'));

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
