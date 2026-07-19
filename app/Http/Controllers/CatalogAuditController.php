<?php

namespace App\Http\Controllers;

use App\Models\CatalogAuditFinding;
use App\Models\CatalogAuditRun;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CatalogAuditController extends Controller
{
    public function missingImages(Request $request, string $shop)
    {
        $auditShop = $this->resolveAuditShop($shop);
        $search = $this->validatedSearch($request);
        $findings = $this->findingsQuery($auditShop, CatalogAuditFinding::TYPE_MISSING_IMAGE, $search)
            ->orderBy('product_title')
            ->orderBy('product_legacy_id')
            ->paginate(25)
            ->withQueryString();

        return view('catalog-audit.index', $this->viewData(
            $auditShop,
            $shop,
            'missing-images',
            $request,
            ['findings' => $findings],
        ));
    }

    public function duplicateSkus(Request $request, string $shop)
    {
        $auditShop = $this->resolveAuditShop($shop);
        $search = $this->validatedSearch($request);
        $groups = $this->findingsQuery($auditShop, CatalogAuditFinding::TYPE_DUPLICATE_SKU, $search)
            ->whereNotNull('normalized_sku')
            ->selectRaw('normalized_sku, COUNT(*) as affected_rows')
            ->groupBy('normalized_sku')
            ->orderBy('normalized_sku')
            ->paginate(10)
            ->withQueryString();
        $normalizedSkus = collect($groups->items())->pluck('normalized_sku');

        $findingsByGroup = $normalizedSkus->isEmpty()
            ? collect()
            : $this->currentFindingsQuery($auditShop, CatalogAuditFinding::TYPE_DUPLICATE_SKU)
                ->whereIn('normalized_sku', $normalizedSkus)
                ->orderBy('normalized_sku')
                ->orderBy('product_title')
                ->orderBy('variant_title')
                ->get()
                ->groupBy('normalized_sku');

        return view('catalog-audit.index', $this->viewData(
            $auditShop,
            $shop,
            'duplicate-skus',
            $request,
            [
                'duplicateGroups' => $groups,
                'findingsByGroup' => $findingsByGroup,
            ],
        ));
    }

    private function findingsQuery(Shop $shop, string $findingType, string $search): Builder
    {
        return $this->currentFindingsQuery($shop, $findingType)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $like = '%'.$search.'%';

                    $inner->where('product_title', 'like', $like)
                        ->orWhere('product_handle', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('normalized_sku', 'like', $like)
                        ->orWhere('variant_title', 'like', $like);

                    if (ctype_digit($search)) {
                        $inner->orWhere('product_legacy_id', (int) $search)
                            ->orWhere('variant_legacy_id', (int) $search);
                    }
                });
            });
    }

    private function validatedSearch(Request $request): string
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        return trim($validated['search'] ?? '');
    }

    private function currentFindingsQuery(Shop $shop, string $findingType): Builder
    {
        return CatalogAuditFinding::query()
            ->where('shop_id', $shop->id)
            ->where('finding_type', $findingType);
    }

    private function resolveAuditShop(string $slug): Shop
    {
        $domain = config('catalog_audit.shops', [])[$slug] ?? null;

        abort_unless($domain, 404);

        return Shop::query()
            ->where('domain', $domain)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function viewData(Shop $shop, string $slug, string $reportType, Request $request, array $reportData): array
    {
        $latestRun = CatalogAuditRun::query()
            ->where('shop_id', $shop->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
        $lastSuccessfulRun = CatalogAuditRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', CatalogAuditRun::STATUS_COMPLETED)
            ->latest('finished_at')
            ->latest('id')
            ->first();

        return [
            ...$reportData,
            'auditShop' => $shop,
            'currentShopSlug' => $slug,
            'reportType' => $reportType,
            'shopTabs' => $this->shopTabs(),
            'filters' => $request->only('search'),
            'latestRun' => $latestRun,
            'lastSuccessfulRun' => $lastSuccessfulRun,
            'currentCounts' => [
                'missing_images' => CatalogAuditFinding::query()
                    ->where('shop_id', $shop->id)
                    ->where('finding_type', CatalogAuditFinding::TYPE_MISSING_IMAGE)
                    ->count(),
                'duplicate_groups' => CatalogAuditFinding::query()
                    ->where('shop_id', $shop->id)
                    ->where('finding_type', CatalogAuditFinding::TYPE_DUPLICATE_SKU)
                    ->whereNotNull('normalized_sku')
                    ->distinct()
                    ->count('normalized_sku'),
                'duplicate_rows' => CatalogAuditFinding::query()
                    ->where('shop_id', $shop->id)
                    ->where('finding_type', CatalogAuditFinding::TYPE_DUPLICATE_SKU)
                    ->count(),
            ],
        ];
    }

    /** @return array<int, array{slug: string, label: string, domain: string}> */
    private function shopTabs(): array
    {
        $configuredShops = config('catalog_audit.shops', []);
        $shopsByDomain = Shop::query()
            ->where('is_active', true)
            ->whereIn('domain', array_values($configuredShops))
            ->get(['name', 'domain'])
            ->keyBy('domain');

        return collect($configuredShops)
            ->map(function (string $domain, string $slug) use ($shopsByDomain): ?array {
                $shop = $shopsByDomain->get($domain);

                return $shop === null ? null : [
                    'slug' => $slug,
                    'label' => $shop->name ?: $domain,
                    'domain' => $domain,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
