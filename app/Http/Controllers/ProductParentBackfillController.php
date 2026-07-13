<?php

namespace App\Http\Controllers;

use App\Models\ProductParentBackfillCandidate;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductParentBackfillController extends Controller
{
    public function index(Request $request)
    {
        $statusOptions = [
            '' => 'Toate corelate',
            ProductParentBackfillCandidate::STATUS_ALREADY_SET => 'Aplicat',
            ProductParentBackfillCandidate::STATUS_MATCHED => 'Gasit, neaplicat',
        ];

        return view('product-parent-backfill.index', [
            'candidates' => $this->baseQuery($request, array_keys(array_filter($statusOptions, fn($label, $value) => $value !== '', ARRAY_FILTER_USE_BOTH)))
                ->correlated()
                ->latest('last_scanned_at')
                ->paginate(50)
                ->withQueryString(),
            'shops' => $this->shops(),
            'filters' => $request->only(['shop_id', 'status', 'search']),
            'statusOptions' => $statusOptions,
            'totals' => $this->totals(),
        ]);
    }

    public function unmatched(Request $request)
    {
        $statusOptions = [
            '' => 'Toate necorelate',
            ProductParentBackfillCandidate::STATUS_UNMATCHED => 'Unmatched',
            ProductParentBackfillCandidate::STATUS_AMBIGUOUS => 'Ambiguous',
        ];
        $sortOptions = [
            'last_scanned_desc' => 'Ultima scanare',
            'status_asc' => 'Ambiguous primul',
            'status_desc' => 'Unmatched primul',
        ];
        $query = $this->baseQuery($request, array_keys(array_filter($statusOptions, fn($label, $value) => $value !== '', ARRAY_FILTER_USE_BOTH)))
            ->uncorrelated();
        $this->applyUnmatchedSort($query, (string)$request->input('sort', 'last_scanned_desc'));

        return view('product-parent-backfill.unmatched', [
            'candidates' => $query
                ->paginate(50)
                ->withQueryString(),
            'shops' => $this->shops(),
            'filters' => $request->only(['shop_id', 'status', 'search', 'sort']),
            'statusOptions' => $statusOptions,
            'sortOptions' => $sortOptions,
            'totals' => $this->totals(),
        ]);
    }

    public function duplicates(Request $request)
    {
        return redirect()->route('product-parent-duplicates.shop', ['shop' => 'lustreled']);
    }

    public function duplicatesByShop(Request $request, string $shop)
    {
        $targetShop = $this->resolveDuplicateShop($shop);
        $duplicateGroups = $this->duplicateGroupsQuery($request)
            ->where('target_shop_id', $targetShop->id)
            ->paginate(10)
            ->withQueryString();

        $candidatesByGroup = $this->duplicateCandidatesForGroups(collect($duplicateGroups->items()));

        return view('product-parent-duplicates.shop', [
            'duplicateGroups' => $duplicateGroups,
            'candidatesByGroup' => $candidatesByGroup,
            'shops' => $this->shops(),
            'targetShop' => $targetShop,
            'currentShopSlug' => $shop,
            'shopTabs' => $this->duplicateShopTabs(),
            'filters' => $request->only(['shop_id', 'search']),
            'totals' => $this->totals(),
            'duplicateStats' => $this->duplicateStats(),
        ]);
    }

    private function baseQuery(Request $request, array $allowedStatuses)
    {
        return ProductParentBackfillCandidate::query()
            ->with(['sourceShop:id,name,domain', 'targetShop:id,name,domain'])
            ->when($request->filled('shop_id'), function ($query) use ($request) {
                $query->where('target_shop_id', (int)$request->input('shop_id'));
            })
            ->when($request->filled('status') && in_array($request->input('status'), $allowedStatuses, true), function ($query) use ($request) {
                $query->where('match_status', $request->input('status'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string)$request->input('search'));
                $query->where(function ($inner) use ($search) {
                    $inner->where('source_title', 'like', '%'.$search.'%')
                        ->orWhere('target_title', 'like', '%'.$search.'%')
                        ->orWhere('source_handle', 'like', '%'.$search.'%')
                        ->orWhere('target_handle', 'like', '%'.$search.'%');

                    if (ctype_digit($search)) {
                        $inner->orWhere('source_product_id', (int)$search)
                            ->orWhere('target_product_id', (int)$search);
                    }
                });
            });
    }

    private function applyUnmatchedSort($query, string $sort): void
    {
        match ($sort) {
            'status_asc' => $query
                ->orderBy('match_status')
                ->latest('last_scanned_at'),
            'status_desc' => $query
                ->orderByDesc('match_status')
                ->latest('last_scanned_at'),
            default => $query->latest('last_scanned_at'),
        };
    }

    private function duplicateGroupsQuery(Request $request)
    {
        return ProductParentBackfillCandidate::query()
            ->selectRaw('
                target_shop_id,
                source_shop_id,
                source_product_id,
                MAX(source_title) as source_title,
                MAX(source_handle) as source_handle,
                MAX(source_status) as source_status,
                MAX(source_image_count) as source_image_count,
                MAX(last_scanned_at) as last_scanned_at,
                COUNT(*) as candidates_count
            ')
            ->whereNotNull('source_product_id')
            ->whereColumn('parentproduct_value', 'source_product_id')
            ->when($request->filled('shop_id'), function ($query) use ($request) {
                $query->where('target_shop_id', (int)$request->input('shop_id'));
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string)$request->input('search'));
                $query->where(function ($inner) use ($search) {
                    $inner->where('source_title', 'like', '%'.$search.'%')
                        ->orWhere('target_title', 'like', '%'.$search.'%')
                        ->orWhere('source_handle', 'like', '%'.$search.'%')
                        ->orWhere('target_handle', 'like', '%'.$search.'%');

                    if (ctype_digit($search)) {
                        $inner->orWhere('source_product_id', (int)$search)
                            ->orWhere('target_product_id', (int)$search)
                            ->orWhere('parentproduct_value', (int)$search);
                    }
                });
            })
            ->groupBy('target_shop_id', 'source_shop_id', 'source_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('last_scanned_at');
    }

    private function duplicateCandidatesForGroups($groups)
    {
        if ($groups->isEmpty()) {
            return collect();
        }

        return ProductParentBackfillCandidate::query()
            ->with(['sourceShop:id,name,domain', 'targetShop:id,name,domain'])
            ->where(function ($query) use ($groups) {
                foreach ($groups as $group) {
                    $query->orWhere(function ($inner) use ($group) {
                        $inner->where('target_shop_id', (int)$group->target_shop_id)
                            ->where('source_product_id', (int)$group->source_product_id)
                            ->whereColumn('parentproduct_value', 'source_product_id');
                    });
                }
            })
            ->orderBy('target_shop_id')
            ->orderBy('source_product_id')
            ->orderBy('target_product_id')
            ->get()
            ->groupBy(fn (ProductParentBackfillCandidate $candidate) => $this->duplicateGroupKey(
                (int)$candidate->target_shop_id,
                (int)$candidate->source_product_id
            ));
    }

    private function duplicateGroupKey(int $targetShopId, int $sourceProductId): string
    {
        return $targetShopId.'-'.$sourceProductId;
    }

    private function resolveDuplicateShop(string $slug): Shop
    {
        $domain = $this->duplicateShopMap()[$slug] ?? null;

        abort_unless($domain, 404);

        return Shop::query()
            ->where('domain', $domain)
            ->firstOrFail();
    }

    private function duplicateShopTabs(): array
    {
        return collect($this->duplicateShopMap())
            ->map(function (string $domain, string $slug) {
                $shop = Shop::query()
                    ->where('domain', $domain)
                    ->first();

                return [
                    'slug' => $slug,
                    'domain' => $domain,
                    'label' => $shop?->name ?: $domain,
                ];
            })
            ->values()
            ->all();
    }

    private function duplicateShopMap(): array
    {
        return [
            'lustreled' => 'lustreled.myshopify.com',
            'powerleds' => 'powerleds-ro.myshopify.com',
            'backup' => 'eiluminatbackup.myshopify.com',
        ];
    }

    private function shops()
    {
        return Shop::query()
            ->select(['id', 'name', 'domain'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    private function totals(): array
    {
        return [
            'correlated' => ProductParentBackfillCandidate::query()->correlated()->count(),
            'unmatched' => ProductParentBackfillCandidate::query()->uncorrelated()->count(),
            'duplicate_sources' => (int)$this->duplicateStats()->sum('source_products_with_duplicates'),
            'already_set' => ProductParentBackfillCandidate::query()
                ->where('match_status', ProductParentBackfillCandidate::STATUS_ALREADY_SET)
                ->count(),
            'matched' => ProductParentBackfillCandidate::query()
                ->where('match_status', ProductParentBackfillCandidate::STATUS_MATCHED)
                ->count(),
            'ambiguous' => ProductParentBackfillCandidate::query()
                ->where('match_status', ProductParentBackfillCandidate::STATUS_AMBIGUOUS)
                ->count(),
        ];
    }

    private function duplicateStats()
    {
        $subQuery = ProductParentBackfillCandidate::query()
            ->selectRaw('target_shop_id, source_product_id, COUNT(*) as candidates_count')
            ->whereNotNull('source_product_id')
            ->whereColumn('parentproduct_value', 'source_product_id')
            ->groupBy('target_shop_id', 'source_product_id')
            ->havingRaw('COUNT(*) > 1');

        $stats = DB::query()
            ->fromSub($subQuery, 'duplicates')
            ->selectRaw('
                target_shop_id,
                COUNT(*) as source_products_with_duplicates,
                SUM(candidates_count) as candidate_rows_involved,
                SUM(candidates_count) - COUNT(*) as extra_rows_to_resolve,
                MAX(candidates_count) as max_candidates_for_one_source
            ')
            ->groupBy('target_shop_id')
            ->get()
            ->keyBy('target_shop_id');

        return $this->shops()
            ->map(function (Shop $shop) use ($stats) {
                $row = $stats->get($shop->id);

                return (object) [
                    'shop_id' => $shop->id,
                    'shop_name' => $shop->name ?: $shop->domain,
                    'shop_domain' => $shop->domain,
                    'source_products_with_duplicates' => (int)($row->source_products_with_duplicates ?? 0),
                    'candidate_rows_involved' => (int)($row->candidate_rows_involved ?? 0),
                    'extra_rows_to_resolve' => (int)($row->extra_rows_to_resolve ?? 0),
                    'max_candidates_for_one_source' => (int)($row->max_candidates_for_one_source ?? 0),
                ];
            });
    }
}
