<?php

namespace Tests\Feature;

use App\Models\CatalogAuditFinding;
use App\Models\CatalogAuditRun;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogAuditControllerTest extends TestCase
{
    public function test_catalog_audit_routes_require_authenticated_users(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("Route::middleware('auth')->group", $routes);
        $this->assertStringContainsString('/dashboard/catalog-audit/{shop}/missing-images', $routes);
        $this->assertStringContainsString("->name('catalog-audit.missing-images')", $routes);
        $this->assertStringContainsString('/dashboard/catalog-audit/{shop}/duplicate-skus', $routes);
        $this->assertStringContainsString("->name('catalog-audit.duplicate-skus')", $routes);

        $this->get('/dashboard/catalog-audit/eiluminat/missing-images')
            ->assertRedirect(route('login'));
        $this->get('/dashboard/catalog-audit/eiluminat/duplicate-skus')
            ->assertRedirect(route('login'));
    }

    public function test_controller_resolves_only_active_configured_shop_slugs(): void
    {
        $controller = $this->controllerSource();

        $this->assertStringContainsString("config('catalog_audit.shops'", $controller);
        $this->assertStringContainsString('abort_unless($domain, 404)', $controller);
        $this->assertStringContainsString("->where('is_active', true)", $controller);
        $this->assertStringContainsString('->firstOrFail()', $controller);
    }

    public function test_missing_image_findings_are_scoped_searched_and_paginated_by_twenty_five(): void
    {
        $controller = $this->controllerSource();

        $this->assertStringContainsString('CatalogAuditFinding::TYPE_MISSING_IMAGE', $controller);
        $this->assertStringContainsString('->where(\'shop_id\', $shop->id)', $controller);
        $this->assertStringContainsString('product_legacy_id', $controller);
        $this->assertStringContainsString('product_title', $controller);
        $this->assertStringContainsString('product_handle', $controller);
        $this->assertStringContainsString('->paginate(25)', $controller);
        $this->assertStringContainsString('->withQueryString()', $controller);
    }

    public function test_duplicate_sku_groups_are_scoped_searched_paginated_and_then_hydrated(): void
    {
        $controller = $this->controllerSource();

        $this->assertStringContainsString('CatalogAuditFinding::TYPE_DUPLICATE_SKU', $controller);
        $this->assertStringContainsString("->groupBy('normalized_sku')", $controller);
        $this->assertStringContainsString('->paginate(10)', $controller);
        $this->assertStringContainsString('->whereIn(\'normalized_sku\', $normalizedSkus)', $controller);
        $this->assertStringContainsString("->orderBy('normalized_sku')", $controller);
        $this->assertStringContainsString("->groupBy('normalized_sku')", $controller);
        $this->assertStringContainsString('currentFindingsQuery($auditShop, CatalogAuditFinding::TYPE_DUPLICATE_SKU)', $controller);
    }

    public function test_view_contract_includes_stale_status_tabs_admin_links_and_custom_css(): void
    {
        $view = file_get_contents(resource_path('views/catalog-audit/index.blade.php'));

        $this->assertStringContainsString('audit-', $view);
        $this->assertStringContainsString('Raportul poate fi neactualizat', $view);
        $this->assertStringContainsString('catalog-audit.missing-images', $view);
        $this->assertStringContainsString('catalog-audit.duplicate-skus', $view);
        $this->assertStringContainsString('Shopify Admin', $view);
        $this->assertStringContainsString('Nu exista constatari', $view);
    }

    public function test_navigation_links_to_the_first_configured_catalog_audit_shop_for_desktop_and_mobile(): void
    {
        $navigation = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

        $this->assertSame(2, substr_count($navigation, "route('catalog-audit.missing-images'"));
        $this->assertStringContainsString("request()->routeIs('catalog-audit.*')", $navigation);
        $this->assertStringContainsString("->where('is_active', true)", $navigation);
    }

    public function test_authenticated_user_can_view_only_active_configured_shop(): void
    {
        $this->bootSqliteDatabase();
        [$user, $activeShop] = $this->seedUserAndShops();

        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/eiluminat/missing-images')
            ->assertOk()
            ->assertSee($activeShop->domain);

        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/inactive/missing-images')
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/not-configured/missing-images')
            ->assertNotFound();
    }

    public function test_missing_images_are_shop_scoped_and_paginated_by_twenty_five(): void
    {
        $this->bootSqliteDatabase();
        [$user, $shop, $otherShop] = $this->seedUserAndShops();
        $run = $this->createRun($shop);
        $otherRun = $this->createRun($otherShop);

        foreach (range(1, 26) as $index) {
            $this->createFinding($shop, $run, CatalogAuditFinding::TYPE_MISSING_IMAGE, 'missing-'.$index, [
                'product_title' => sprintf('Missing %02d', $index),
                'product_legacy_id' => 1000 + $index,
            ]);
        }
        $this->createFinding($otherShop, $otherRun, CatalogAuditFinding::TYPE_MISSING_IMAGE, 'other', [
            'product_title' => 'Other shop secret',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/eiluminat/missing-images')
            ->assertOk()
            ->assertSee('Missing 01')
            ->assertDontSee('Missing 26')
            ->assertDontSee('Other shop secret');

        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/eiluminat/missing-images?page=2&search=Missing')
            ->assertOk()
            ->assertSee('Missing 26')
            ->assertSee('search=Missing', false);
    }

    public function test_duplicate_groups_paginate_by_ten_and_search_hydrates_all_siblings(): void
    {
        $this->bootSqliteDatabase();
        [$user, $shop] = $this->seedUserAndShops();
        $run = $this->createRun($shop);

        foreach (range(1, 11) as $group) {
            foreach (range(1, 2) as $row) {
                $this->createFinding($shop, $run, CatalogAuditFinding::TYPE_DUPLICATE_SKU, "group-{$group}-{$row}", [
                    'normalized_sku' => sprintf('SKU-%02d', $group),
                    'sku' => sprintf('sku-%02d', $group),
                    'product_title' => "Group {$group} product {$row}",
                    'variant_title' => "Variant {$group}-{$row}",
                ]);
            }
        }

        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/eiluminat/duplicate-skus')
            ->assertOk()
            ->assertSee('SKU-01')
            ->assertDontSee('SKU-11');
        $this->actingAs($user)
            ->get('/dashboard/catalog-audit/eiluminat/duplicate-skus?page=2')
            ->assertOk()
            ->assertSee('SKU-11');

        foreach (range(1, 4) as $row) {
            $this->createFinding($shop, $run, CatalogAuditFinding::TYPE_DUPLICATE_SKU, "searched-{$row}", [
                'normalized_sku' => 'SEARCHED-SKU',
                'sku' => 'searched-sku',
                'product_title' => $row === 1 ? 'Needle product' : "Sibling {$row}",
                'variant_title' => "Searched variant {$row}",
            ]);
        }

        $response = $this->actingAs($user)
            ->get('/dashboard/catalog-audit/eiluminat/duplicate-skus?search=Needle')
            ->assertOk()
            ->assertSee('4 variante afectate');

        foreach (range(1, 4) as $row) {
            $response->assertSee("Searched variant {$row}");
        }
    }

    public function test_search_rejects_non_scalar_or_oversized_input(): void
    {
        $this->bootSqliteDatabase();
        [$user] = $this->seedUserAndShops();

        $this->actingAs($user)
            ->from('/dashboard/catalog-audit/eiluminat/missing-images')
            ->get('/dashboard/catalog-audit/eiluminat/missing-images?search[]=bad')
            ->assertRedirect('/dashboard/catalog-audit/eiluminat/missing-images')
            ->assertSessionHasErrors('search');

        $this->actingAs($user)
            ->from('/dashboard/catalog-audit/eiluminat/missing-images')
            ->get('/dashboard/catalog-audit/eiluminat/missing-images?search='.str_repeat('x', 201))
            ->assertSessionHasErrors('search');
    }

    private function controllerSource(): string
    {
        $path = app_path('Http/Controllers/CatalogAuditController.php');

        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function bootSqliteDatabase(): void
    {
        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for catalog audit HTTP integration tests.');
        }

        config([
            'database.default' => 'catalog_audit_test',
            'database.connections.catalog_audit_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'catalog_audit.shops' => [
                'eiluminat' => 'eiluminat.myshopify.com',
                'inactive' => 'inactive.myshopify.com',
                'other' => 'other.myshopify.com',
            ],
        ]);
        DB::purge('catalog_audit_test');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->text('access_token')->nullable();
            $table->string('api_version')->nullable();
            $table->boolean('is_source')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('location_legacy_id')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('missing_image_count')->default(0);
            $table->unsignedInteger('duplicate_sku_group_count')->default(0);
            $table->unsignedInteger('duplicate_sku_row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_audit_findings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('last_seen_run_id');
            $table->string('finding_type');
            $table->string('fingerprint');
            $table->string('product_gid');
            $table->unsignedBigInteger('product_legacy_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_handle')->nullable();
            $table->string('product_status')->nullable();
            $table->string('variant_gid')->nullable();
            $table->unsignedBigInteger('variant_legacy_id')->nullable();
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable();
            $table->string('normalized_sku')->nullable();
            $table->string('shopify_admin_url')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /** @return array{0: User, 1: Shop, 2: Shop} */
    private function seedUserAndShops(): array
    {
        $user = User::query()->create([
            'name' => 'Audit User',
            'email' => 'audit@example.test',
            'password' => Hash::make('secret'),
        ]);
        $shop = Shop::query()->create([
            'name' => 'eIluminat',
            'domain' => 'eiluminat.myshopify.com',
            'is_active' => true,
        ]);
        Shop::query()->create([
            'name' => 'Inactive',
            'domain' => 'inactive.myshopify.com',
            'is_active' => false,
        ]);
        $otherShop = Shop::query()->create([
            'name' => 'Other',
            'domain' => 'other.myshopify.com',
            'is_active' => true,
        ]);

        return [$user, $shop, $otherShop];
    }

    private function createRun(Shop $shop): CatalogAuditRun
    {
        return CatalogAuditRun::query()->create([
            'shop_id' => $shop->id,
            'status' => CatalogAuditRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createFinding(Shop $shop, CatalogAuditRun $run, string $type, string $fingerprint, array $overrides = []): CatalogAuditFinding
    {
        static $sequence = 0;
        $sequence++;

        return CatalogAuditFinding::query()->create(array_merge([
            'shop_id' => $shop->id,
            'last_seen_run_id' => $run->id,
            'finding_type' => $type,
            'fingerprint' => $fingerprint,
            'product_gid' => 'gid://shopify/Product/'.(900000 + $sequence),
            'product_legacy_id' => 900000 + $sequence,
            'product_title' => 'Product '.$sequence,
            'product_status' => 'ACTIVE',
            'shopify_admin_url' => 'https://admin.shopify.com/store/test/products/'.(900000 + $sequence),
            'last_seen_at' => now(),
        ], $overrides));
    }
}
