<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 style="margin:0;font-size:20px;font-weight:700;color:inherit;">Audit catalog - {{ $auditShop->name ?: $auditShop->domain }}</h2>
            <div style="margin-top:4px;font-size:13px;color:#64748b;">{{ $auditShop->domain }}</div>
        </div>
    </x-slot>

    <style>
        .audit-page { min-height: 100vh; background: #f4f7fb; color: #172033; padding: 28px 18px 48px; }
        .audit-wrap { max-width: 1500px; margin: 0 auto; }
        .audit-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .audit-tab { display: inline-flex; align-items: center; min-height: 38px; padding: 0 14px; border: 1px solid #c7d2e0; border-radius: 7px; background: #fff; color: #334155; font-size: 14px; font-weight: 700; text-decoration: none; }
        .audit-tab:hover { background: #edf3fa; }
        .audit-tab.is-active { border-color: #146c94; background: #146c94; color: #fff; }
        .audit-report-tabs { display: flex; gap: 18px; margin: 4px 0 20px; border-bottom: 1px solid #cbd5e1; }
        .audit-report-tab { padding: 10px 2px; border-bottom: 3px solid transparent; color: #536274; font-size: 14px; font-weight: 800; text-decoration: none; }
        .audit-report-tab.is-active { border-color: #d36f21; color: #172033; }
        .audit-status { display: grid; grid-template-columns: minmax(0, 1.5fr) repeat(3, minmax(130px, .65fr)); gap: 14px; margin-bottom: 18px; padding: 16px; border: 1px solid #d6e0ea; background: #fff; }
        .audit-status-label { color: #64748b; font-size: 11px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; }
        .audit-status-value { margin-top: 6px; color: #172033; font-size: 17px; font-weight: 800; line-height: 1.25; }
        .audit-status-meta { margin-top: 5px; color: #64748b; font-size: 12px; line-height: 1.45; }
        .audit-badge { display: inline-flex; align-items: center; min-height: 24px; padding: 0 8px; border: 1px solid #b9c8d8; border-radius: 999px; background: #f5f8fc; color: #334155; font-size: 12px; font-weight: 800; text-transform: capitalize; }
        .audit-badge.completed { border-color: #9ed3b0; background: #e9f8ef; color: #19633a; }
        .audit-badge.failed { border-color: #f1b7ad; background: #fff0ed; color: #a33328; }
        .audit-badge.running, .audit-badge.queued { border-color: #a9c8e5; background: #ecf6ff; color: #1c5b8d; }
        .audit-warning { margin: -4px 0 18px; padding: 12px 14px; border-left: 4px solid #c7621a; background: #fff5e9; color: #713c18; font-size: 14px; line-height: 1.45; }
        .audit-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .audit-stat { padding: 16px; border: 1px solid #d6e0ea; background: #fff; }
        .audit-stat-value { margin-top: 8px; color: #172033; font-size: 28px; font-weight: 800; line-height: 1; }
        .audit-filter { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: end; margin-bottom: 18px; padding: 16px; border: 1px solid #d6e0ea; background: #fff; }
        .audit-label { display: block; margin-bottom: 7px; color: #526174; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .audit-input { width: 100%; height: 42px; box-sizing: border-box; border: 1px solid #b9c8d8; border-radius: 6px; background: #fff; color: #172033; padding: 0 11px; font-size: 14px; }
        .audit-input:focus { border-color: #146c94; box-shadow: 0 0 0 3px rgba(20, 108, 148, .16); outline: none; }
        .audit-actions { display: flex; gap: 8px; }
        .audit-button { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 13px; border: 1px solid transparent; border-radius: 6px; background: #146c94; color: #fff; font-size: 13px; font-weight: 800; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .audit-button:hover { background: #0e587a; }
        .audit-button-secondary { border-color: #b9c8d8; background: #fff; color: #334155; }
        .audit-button-secondary:hover { background: #edf3fa; }
        .audit-button-admin { min-height: 32px; background: #27744c; }
        .audit-button-admin:hover { background: #1d5d3c; }
        .audit-results { border: 1px solid #d6e0ea; background: #fff; overflow: hidden; }
        .audit-scroll { overflow-x: auto; }
        .audit-table { width: 100%; min-width: 940px; border-collapse: collapse; font-size: 14px; }
        .audit-table th { padding: 13px 15px; border-bottom: 1px solid #cbd5e1; background: #eaf0f6; color: #405068; font-size: 11px; font-weight: 900; letter-spacing: .03em; text-align: left; text-transform: uppercase; }
        .audit-table td { padding: 14px 15px; border-bottom: 1px solid #e1e8f0; color: #1f2937; vertical-align: top; }
        .audit-table tr:last-child td { border-bottom: 0; }
        .audit-title { color: #172033; font-weight: 800; line-height: 1.4; }
        .audit-meta { margin-top: 5px; color: #64748b; font-size: 12px; line-height: 1.45; overflow-wrap: anywhere; }
        .audit-sku { display: inline-block; max-width: 280px; overflow-wrap: anywhere; color: #25364d; font-family: monospace; font-size: 13px; font-weight: 700; }
        .audit-group { padding: 18px; border-bottom: 1px solid #d6e0ea; }
        .audit-group:last-child { border-bottom: 0; }
        .audit-group-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .audit-group-count { color: #64748b; font-size: 13px; white-space: nowrap; }
        .audit-empty { padding: 48px 18px; color: #64748b; font-size: 14px; text-align: center; }
        .audit-pagination { padding: 14px 16px; border-top: 1px solid #d6e0ea; }
        .audit-pagination nav > div { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .audit-pagination nav a, .audit-pagination nav span[aria-disabled="true"], .audit-pagination nav span[aria-current="page"] > span { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; min-height: 34px; padding: 0 8px; border: 1px solid #cbd5e1; border-radius: 5px; background: #fff; color: #334155; font-size: 13px; text-decoration: none; }
        .audit-pagination nav span[aria-current="page"] > span { border-color: #146c94; background: #146c94; color: #fff; }
        @media (max-width: 880px) { .audit-page { padding: 20px 12px 36px; } .audit-status, .audit-stats, .audit-filter { grid-template-columns: 1fr; } .audit-actions { width: 100%; } .audit-actions .audit-button { flex: 1; } .audit-report-tabs { gap: 12px; } }
    </style>

    @php
        $isMissingImages = $reportType === 'missing-images';
        $routeName = $isMissingImages ? 'catalog-audit.missing-images' : 'catalog-audit.duplicate-skus';
        $currentTotal = $isMissingImages ? $currentCounts['missing_images'] : $currentCounts['duplicate_groups'];
    @endphp

    <div class="audit-page">
        <div class="audit-wrap">
            <div class="audit-tabs" aria-label="Magazine">
                @foreach ($shopTabs as $tab)
                    <a class="audit-tab {{ $currentShopSlug === $tab['slug'] ? 'is-active' : '' }}" href="{{ route($routeName, ['shop' => $tab['slug']]) }}">{{ $tab['label'] }}</a>
                @endforeach
            </div>

            <div class="audit-report-tabs" aria-label="Rapoarte audit">
                <a class="audit-report-tab {{ $isMissingImages ? 'is-active' : '' }}" href="{{ route('catalog-audit.missing-images', ['shop' => $currentShopSlug]) }}">Produse fara imagini</a>
                <a class="audit-report-tab {{ ! $isMissingImages ? 'is-active' : '' }}" href="{{ route('catalog-audit.duplicate-skus', ['shop' => $currentShopSlug]) }}">SKU-uri duplicate</a>
            </div>

            <section class="audit-status" aria-label="Starea ultimei rulări">
                <div>
                    <div class="audit-status-label">Ultima rulare</div>
                    <div class="audit-status-value">
                        @if ($latestRun)
                            <span class="audit-badge {{ $latestRun->status }}">{{ $latestRun->status }}</span>
                        @else
                            Nu exista rulări
                        @endif
                    </div>
                    <div class="audit-status-meta">
                        @if ($latestRun?->finished_at)
                            Finalizata: {{ $latestRun->finished_at->format('d.m.Y H:i') }}
                        @elseif ($latestRun?->started_at)
                            Pornita: {{ $latestRun->started_at->format('d.m.Y H:i') }}
                        @elseif ($latestRun)
                            Programata: {{ $latestRun->created_at->format('d.m.Y H:i') }}
                        @else
                            Auditul nu a fost rulat pentru acest magazin.
                        @endif
                    </div>
                </div>
                <div>
                    <div class="audit-status-label">Fara imagini</div>
                    <div class="audit-status-value">{{ $currentCounts['missing_images'] }}</div>
                    <div class="audit-status-meta">constatari curente</div>
                </div>
                <div>
                    <div class="audit-status-label">Grupuri SKU</div>
                    <div class="audit-status-value">{{ $currentCounts['duplicate_groups'] }}</div>
                    <div class="audit-status-meta">constatari curente</div>
                </div>
                <div>
                    <div class="audit-status-label">Randuri SKU</div>
                    <div class="audit-status-value">{{ $currentCounts['duplicate_rows'] }}</div>
                    <div class="audit-status-meta">variante afectate</div>
                </div>
            </section>

            @if ($latestRun?->status === \App\Models\CatalogAuditRun::STATUS_FAILED)
                <div class="audit-warning">
                    <strong>Raportul poate fi neactualizat.</strong>
                    Rularea din {{ optional($latestRun->finished_at)->format('d.m.Y H:i') ?: 'ultima actualizare' }} a esuat; sunt afisate constatarile ultimei rulări reusite{{ $lastSuccessfulRun?->finished_at ? ' din '.$lastSuccessfulRun->finished_at->format('d.m.Y H:i') : '' }}.
                    @if ($latestRun->error_message) Detalii: {{ $latestRun->error_message }} @endif
                </div>
            @endif

            <section class="audit-stats" aria-label="Rezumat raport">
                <div class="audit-stat">
                    <div class="audit-status-label">{{ $isMissingImages ? 'Produse fara imagini' : 'Grupuri SKU duplicate' }}</div>
                    <div class="audit-stat-value">{{ $currentTotal }}</div>
                </div>
                <div class="audit-stat">
                    <div class="audit-status-label">Ultima rulare reusita</div>
                    <div class="audit-stat-value" style="font-size:17px;line-height:1.25;">{{ $lastSuccessfulRun?->finished_at?->format('d.m.Y H:i') ?: 'Nedisponibil' }}</div>
                </div>
                <div class="audit-stat">
                    <div class="audit-status-label">Afisare</div>
                    <div class="audit-stat-value">{{ $isMissingImages ? '25' : '10' }}</div>
                    <div class="audit-status-meta">{{ $isMissingImages ? 'produse pe pagina' : 'grupuri pe pagina' }}</div>
                </div>
            </section>

            <form method="GET" action="{{ route($routeName, ['shop' => $currentShopSlug]) }}" class="audit-filter">
                <div>
                    <label class="audit-label" for="search">Cauta</label>
                    <input class="audit-input" id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="ID produs sau varianta, titlu, handle sau SKU">
                </div>
                <div class="audit-actions">
                    <button class="audit-button" type="submit">Filtreaza</button>
                    <a class="audit-button audit-button-secondary" href="{{ route($routeName, ['shop' => $currentShopSlug]) }}">Reseteaza</a>
                </div>
            </form>

            <section class="audit-results" aria-live="polite">
                @if ($isMissingImages)
                    @if ($findings->isEmpty())
                        <div class="audit-empty">Nu exista constatari pentru produsele fara imagini.</div>
                    @else
                        <div class="audit-scroll">
                            <table class="audit-table">
                                <thead><tr><th>Produs</th><th>ID / handle</th><th>Ultima confirmare</th><th>Actiune</th></tr></thead>
                                <tbody>
                                    @foreach ($findings as $finding)
                                        <tr>
                                            <td><div class="audit-title">{{ $finding->product_title ?: 'Fara titlu' }}</div><div class="audit-meta">Status: {{ $finding->product_status ?: 'necunoscut' }}</div></td>
                                            <td><div>{{ $finding->product_legacy_id ? '#'.$finding->product_legacy_id : 'ID indisponibil' }}</div><div class="audit-meta">{{ $finding->product_handle ? '/'.$finding->product_handle : 'Handle indisponibil' }}</div></td>
                                            <td>{{ $finding->last_seen_at?->format('d.m.Y H:i') ?: 'Nedisponibil' }}</td>
                                            <td>@if ($finding->shopify_admin_url)<a class="audit-button audit-button-admin" href="{{ $finding->shopify_admin_url }}" target="_blank" rel="noopener noreferrer">Shopify Admin</a>@else <span class="audit-meta">Link indisponibil</span> @endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="audit-pagination">{{ $findings->links() }}</div>
                    @endif
                @elseif ($duplicateGroups->isEmpty())
                    <div class="audit-empty">Nu exista constatari pentru SKU-uri duplicate.</div>
                @else
                    @foreach ($duplicateGroups as $group)
                        <article class="audit-group">
                            <div class="audit-group-heading">
                                <div><div class="audit-status-label">SKU normalizat</div><div class="audit-sku">{{ $group->normalized_sku }}</div></div>
                                <div class="audit-group-count">{{ $findingsByGroup->get($group->normalized_sku, collect())->count() }} variante afectate</div>
                            </div>
                            <div class="audit-scroll">
                                <table class="audit-table">
                                    <thead><tr><th>Produs</th><th>Varianta</th><th>SKU original</th><th>ID / handle</th><th>Actiune</th></tr></thead>
                                    <tbody>
                                        @foreach ($findingsByGroup->get($group->normalized_sku, collect()) as $finding)
                                            <tr>
                                                <td><div class="audit-title">{{ $finding->product_title ?: 'Fara titlu' }}</div><div class="audit-meta">{{ $finding->product_status ?: 'Status necunoscut' }}</div></td>
                                                <td><div class="audit-title">{{ $finding->variant_title ?: 'Varianta implicita' }}</div><div class="audit-meta">{{ $finding->variant_legacy_id ? '#'.$finding->variant_legacy_id : 'ID indisponibil' }}</div></td>
                                                <td><span class="audit-sku">{{ $finding->sku ?: 'Indisponibil' }}</span></td>
                                                <td><div>{{ $finding->product_legacy_id ? '#'.$finding->product_legacy_id : 'ID indisponibil' }}</div><div class="audit-meta">{{ $finding->product_handle ? '/'.$finding->product_handle : 'Handle indisponibil' }}</div></td>
                                                <td>@if ($finding->shopify_admin_url)<a class="audit-button audit-button-admin" href="{{ $finding->shopify_admin_url }}" target="_blank" rel="noopener noreferrer">Shopify Admin</a>@else <span class="audit-meta">Link indisponibil</span> @endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    @endforeach
                    <div class="audit-pagination">{{ $duplicateGroups->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
