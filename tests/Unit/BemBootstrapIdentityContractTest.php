<?php

namespace Tests\Unit;

use App\Services\Shopify\BemWatermark\BemWatermarkUpdateBootstrapService;
use App\Services\Shopify\ShopifyParentIdentityResolver;
use ReflectionClass;
use Tests\TestCase;

class BemBootstrapIdentityContractTest extends TestCase
{
    public function test_bem_bootstrap_depends_on_the_strict_parent_identity_resolver(): void
    {
        $constructor = (new ReflectionClass(BemWatermarkUpdateBootstrapService::class))
            ->getConstructor();
        $types = array_map(
            static fn ($parameter): ?string => $parameter->getType()?->getName(),
            $constructor?->getParameters() ?? []
        );

        $this->assertContains(ShopifyParentIdentityResolver::class, $types);
    }

    public function test_bem_bootstrap_contains_no_mutable_field_or_snapshot_identity_fallback(): void
    {
        $source = file_get_contents(
            app_path('Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php')
        );

        $this->assertStringNotContainsString('ProductParentBackfillCandidate', $source);
        $this->assertStringNotContainsString("'handle:'.", $source);
        $this->assertStringNotContainsString("'sku:'.", $source);
        $this->assertStringNotContainsString('ambiguous_parentproduct_snapshot', $source);
    }

    public function test_bem_bootstrap_repairs_only_a_known_backup_product_with_missing_identity(): void
    {
        $source = file_get_contents(
            app_path('Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php')
        );

        $this->assertStringContainsString("\$role === 'backup'", $source);
        $this->assertStringContainsString('repairMissingParentProduct', $source);
        $this->assertStringContainsString('target_product_gid', $source);
    }
}
