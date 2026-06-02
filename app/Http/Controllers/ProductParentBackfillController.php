<?php

namespace App\Http\Controllers;

use App\Models\ProductParentBackfillCandidate;
use App\Models\Shop;
use Illuminate\Http\Request;

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
}
