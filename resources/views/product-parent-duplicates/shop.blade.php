<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 style="margin:0;font-size:20px;font-weight:700;color:#111827;">
                Duplicate parentproduct - {{ $targetShop->name ?: $targetShop->domain }}
            </h2>
            <div style="margin-top:4px;font-size:13px;color:#64748b;">
                {{ $targetShop->domain }}
            </div>
        </div>
    </x-slot>

    <style>
        .dup-page {
            min-height: 100vh;
            background: #f3f6fb;
            color: #111827;
            padding: 28px 18px 48px;
        }

        .dup-wrap {
            max-width: 1540px;
            margin: 0 auto;
        }

        .dup-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .dup-tab {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            padding: 0 16px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }

        .dup-tab:hover {
            background: #eef2f7;
        }

        .dup-tab.is-active {
            border-color: #2563eb;
            background: #2563eb;
            color: #ffffff;
        }

        .dup-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .dup-stat {
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #ffffff;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }

        .dup-stat-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        .dup-stat-value {
            margin-top: 8px;
            color: #0f172a;
            font-size: 30px;
            line-height: 1;
            font-weight: 800;
        }

        .dup-filter {
            margin-bottom: 18px;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #ffffff;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }

        .dup-filter-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: end;
        }

        .dup-label {
            display: block;
            margin-bottom: 7px;
            color: #475569;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .dup-input {
            width: 100%;
            height: 44px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #111827;
            padding: 0 12px;
            font-size: 14px;
            outline: none;
        }

        .dup-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
        }

        .dup-actions {
            display: flex;
            gap: 10px;
        }

        .dup-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
        }

        .dup-btn-primary {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);
        }

        .dup-btn-primary:hover {
            background: #1d4ed8;
        }

        .dup-btn-source {
            background: #059669;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(5, 150, 105, 0.24);
        }

        .dup-btn-source:hover {
            background: #047857;
        }

        .dup-btn-muted {
            border-color: #cbd5e1;
            background: #ffffff;
            color: #334155;
        }

        .dup-btn-muted:hover {
            background: #f1f5f9;
        }

        .dup-table-card {
            overflow: hidden;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }

        .dup-table-scroll {
            overflow-x: auto;
        }

        .dup-table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
            font-size: 14px;
        }

        .dup-table th {
            background: #e8eef7;
            color: #334155;
            padding: 14px 16px;
            border-bottom: 1px solid #cbd5e1;
            text-align: left;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .dup-table td {
            vertical-align: top;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            color: #111827;
        }

        .dup-table tr:last-child td {
            border-bottom: 0;
        }

        .dup-source-title,
        .dup-target-title {
            color: #0f172a;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.35;
        }

        .dup-meta {
            margin-top: 6px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .dup-target-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .dup-target {
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: #f8fafc;
            padding: 14px;
        }

        .dup-target-head {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            justify-content: space-between;
        }

        .dup-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 12px;
        }

        .dup-badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 0 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .dup-badge-green {
            border: 1px solid #86efac;
            background: #dcfce7;
            color: #166534;
        }

        .dup-badge-blue {
            border: 1px solid #93c5fd;
            background: #dbeafe;
            color: #1e40af;
        }

        .dup-badge-gray {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
        }

        .dup-badge-amber {
            border: 1px solid #fcd34d;
            background: #fef3c7;
            color: #92400e;
        }

        .dup-status {
            display: inline-flex;
            min-height: 28px;
            align-items: center;
            padding: 0 10px;
            border: 1px solid #fcd34d;
            border-radius: 999px;
            background: #fef3c7;
            color: #92400e;
            font-size: 12px;
            font-weight: 900;
        }

        .dup-empty {
            padding: 42px 16px !important;
            text-align: center;
            color: #64748b !important;
        }

        .dup-pagination {
            border-top: 1px solid #e2e8f0;
            padding: 14px 16px;
            background: #ffffff;
        }

        @media (max-width: 1050px) {
            .dup-stats,
            .dup-filter-grid,
            .dup-target-grid {
                grid-template-columns: 1fr;
            }

            .dup-actions {
                align-items: stretch;
            }

            .dup-btn {
                width: 100%;
            }
        }
    </style>

    <div class="dup-page">
        <div class="dup-wrap">
            @php
                $adminUrl = function ($shopDomain, $productId) {
                    if (!$shopDomain || !$productId) {
                        return null;
                    }

                    $store = \Illuminate\Support\Str::before($shopDomain, '.myshopify.com');

                    return 'https://admin.shopify.com/store/'.$store.'/products/'.$productId;
                };

                $currentStats = $duplicateStats->firstWhere('shop_id', $targetShop->id);
            @endphp

            <div class="dup-tabs">
                @foreach ($shopTabs as $tab)
                    <a
                        href="{{ route('product-parent-duplicates.shop', ['shop' => $tab['slug']]) }}"
                        class="dup-tab {{ $currentShopSlug === $tab['slug'] ? 'is-active' : '' }}"
                    >
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </div>

            <div class="dup-stats">
                <div class="dup-stat">
                    <div class="dup-stat-label">Produse sursa cu duplicate</div>
                    <div class="dup-stat-value">{{ $currentStats?->source_products_with_duplicates ?? 0 }}</div>
                </div>
                <div class="dup-stat">
                    <div class="dup-stat-label">Randuri candidate implicate</div>
                    <div class="dup-stat-value">{{ $currentStats?->candidate_rows_involved ?? 0 }}</div>
                </div>
                <div class="dup-stat">
                    <div class="dup-stat-label">Legaturi in plus de rezolvat</div>
                    <div class="dup-stat-value">{{ $currentStats?->extra_rows_to_resolve ?? 0 }}</div>
                </div>
            </div>

            <form method="GET" action="{{ route('product-parent-duplicates.shop', ['shop' => $currentShopSlug]) }}" class="dup-filter">
                <div class="dup-filter-grid">
                    <div>
                        <label for="search" class="dup-label">Cauta</label>
                        <input
                            id="search"
                            name="search"
                            type="search"
                            value="{{ $filters['search'] ?? '' }}"
                            placeholder="ID, titlu, handle sau parentproduct"
                            class="dup-input"
                        >
                    </div>

                    <div class="dup-actions">
                        <button type="submit" class="dup-btn dup-btn-primary">
                            Filtreaza
                        </button>
                        <a href="{{ route('product-parent-duplicates.shop', ['shop' => $currentShopSlug]) }}" class="dup-btn dup-btn-muted">
                            Reset
                        </a>
                    </div>
                </div>
            </form>

            <div class="dup-table-card">
                <div class="dup-table-scroll">
                    <table class="dup-table">
                        <thead>
                            <tr>
                                <th style="width:25%;">Produs sursa</th>
                                <th style="width:52%;">Produse duplicate in {{ $targetShop->name ?: $targetShop->domain }}</th>
                                <th>Status</th>
                                <th>Scanat</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($duplicateGroups as $group)
                                @php
                                    $groupKey = ((int)$group->target_shop_id).'-'.((int)$group->source_product_id);
                                    $candidates = $candidatesByGroup->get($groupKey, collect());
                                    $sourceCandidate = $candidates->first();
                                    $sourceAdminUrl = $adminUrl($sourceCandidate?->sourceShop?->domain, $group->source_product_id);
                                @endphp

                                <tr>
                                    <td>
                                        <div class="dup-source-title">{{ $group->source_title ?: '-' }}</div>
                                        <div class="dup-meta">ID: {{ $group->source_product_id }}</div>
                                        <div class="dup-meta">Handle: {{ $group->source_handle ?: '-' }}</div>
                                        <div class="dup-meta">Imagini: {{ $group->source_image_count }}</div>
                                        @if ($sourceAdminUrl)
                                            <div style="margin-top:12px;">
                                                <a href="{{ $sourceAdminUrl }}" target="_blank" rel="noopener noreferrer" class="dup-btn dup-btn-source">
                                                    Deschide sursa
                                                </a>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="dup-target-grid">
                                            @foreach ($candidates as $candidate)
                                                @php
                                                    $targetAdminUrl = $adminUrl($candidate->targetShop?->domain, $candidate->target_product_id);
                                                    $handleMatches = $candidate->target_handle && $candidate->source_handle && $candidate->target_handle === $candidate->source_handle;
                                                    $skuMatches = count(array_intersect($candidate->target_skus ?: [], $candidate->source_skus ?: [])) > 0;
                                                @endphp

                                                <div class="dup-target">
                                                    <div class="dup-target-head">
                                                        <div>
                                                            <div class="dup-target-title">{{ $candidate->target_title ?: '-' }}</div>
                                                            <div class="dup-meta">ID: {{ $candidate->target_product_id }}</div>
                                                            <div class="dup-meta">Handle: {{ $candidate->target_handle ?: '-' }}</div>
                                                            <div class="dup-meta">SKU: {{ implode(', ', $candidate->target_skus ?: []) ?: '-' }}</div>
                                                            <div class="dup-meta">Status: {{ $candidate->target_status ?: '-' }} | Imagini: {{ $candidate->target_image_count }}</div>
                                                        </div>

                                                        @if ($targetAdminUrl)
                                                            <a href="{{ $targetAdminUrl }}" target="_blank" rel="noopener noreferrer" class="dup-btn dup-btn-primary">
                                                                Deschide produs
                                                            </a>
                                                        @endif
                                                    </div>

                                                    <div class="dup-badges">
                                                        @if ($handleMatches)
                                                            <span class="dup-badge dup-badge-green">handle identic</span>
                                                        @endif
                                                        @if ($skuMatches)
                                                            <span class="dup-badge dup-badge-blue">SKU comun</span>
                                                        @endif
                                                        <span class="dup-badge dup-badge-gray">
                                                            {{ $candidate->match_status }} / {{ $candidate->match_strategy ?: '-' }}
                                                        </span>
                                                        <span class="dup-badge dup-badge-amber">
                                                            parent: {{ $candidate->parentproduct_value ?: '-' }}
                                                        </span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <span class="dup-status">{{ $group->candidates_count }} duplicate</span>
                                        <div class="dup-meta">Sursa: {{ $group->source_status ?: '-' }}</div>
                                    </td>
                                    <td>
                                        <div class="dup-meta">
                                            {{ $group->last_scanned_at ? \Illuminate\Support\Carbon::parse($group->last_scanned_at)->format('Y-m-d H:i') : '-' }}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="dup-empty">
                                        Nu exista duplicate pentru filtrele curente.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="dup-pagination">
                    {{ $duplicateGroups->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
