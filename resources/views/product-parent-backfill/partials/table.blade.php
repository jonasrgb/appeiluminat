@php
    $badgeClasses = [
        'already_set' => 'bg-emerald-100 text-emerald-800',
        'matched' => 'bg-blue-100 text-blue-800',
        'unmatched' => 'bg-gray-100 text-gray-800',
        'ambiguous' => 'bg-amber-100 text-amber-800',
    ];
@endphp

<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Magazin</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Target</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Sursa</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">SKU</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Imagini</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Corelat</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Match</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Scanat</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
            @forelse ($candidates as $candidate)
                <tr>
                    <td class="px-4 py-3 align-top">
                        <div class="font-medium text-gray-900">{{ $candidate->targetShop?->name ?: $candidate->targetShop?->domain }}</div>
                        <div class="text-xs text-gray-500">{{ $candidate->targetShop?->domain }}</div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="font-medium text-gray-900">{{ $candidate->target_title ?: '-' }}</div>
                        <div class="mt-1 text-xs text-gray-500">ID: {{ $candidate->target_product_id }}</div>
                        <div class="text-xs text-gray-500">Handle: {{ $candidate->target_handle ?: '-' }}</div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        @if ($candidate->source_product_id)
                            <div class="font-medium text-gray-900">{{ $candidate->source_title ?: '-' }}</div>
                            <div class="mt-1 text-xs text-gray-500">ID: {{ $candidate->source_product_id }}</div>
                            <div class="text-xs text-gray-500">Handle: {{ $candidate->source_handle ?: '-' }}</div>
                        @else
                            <span class="text-gray-500">Negasit</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="text-xs text-gray-500">Target</div>
                        <div class="max-w-56 break-words text-gray-900">
                            {{ implode(', ', $candidate->target_skus ?: []) ?: '-' }}
                        </div>
                        <div class="mt-2 text-xs text-gray-500">Sursa</div>
                        <div class="max-w-56 break-words text-gray-900">
                            {{ implode(', ', $candidate->source_skus ?: []) ?: '-' }}
                        </div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="text-gray-900">Target: {{ $candidate->target_status ?: '-' }}</div>
                        <div class="text-gray-500">Sursa: {{ $candidate->source_status ?: '-' }}</div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <div class="text-gray-900">Target: {{ $candidate->target_image_count }}</div>
                        <div class="text-gray-500">Sursa: {{ $candidate->source_image_count }}</div>
                    </td>
                    <td class="px-4 py-3 align-top">
                        @if ($candidate->source_product_id && (int) $candidate->parentproduct_value === (int) $candidate->source_product_id)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800">
                                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-600 text-white">&#10003;</span>
                                Aplicat
                            </span>
                        @elseif ($candidate->match_status === 'matched')
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-800">
                                Gasit
                            </span>
                        @elseif ($candidate->match_status === 'ambiguous')
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">
                                Ambiguu
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700">
                                Necorelat
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 align-top">
                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $badgeClasses[$candidate->match_status] ?? 'bg-gray-100 text-gray-800' }}">
                            {{ $candidate->match_status }}
                        </span>
                        <div class="mt-2 text-xs text-gray-500">{{ $candidate->match_strategy ?: '-' }}</div>
                        @if ($candidate->parentproduct_value)
                            <div class="mt-1 text-xs text-gray-500">parent: {{ $candidate->parentproduct_value }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 align-top text-gray-500">
                        {{ $candidate->last_scanned_at?->format('Y-m-d H:i') ?: '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-4 py-10 text-center text-gray-500">
                        <div>Nu exista rezultate pentru filtrele curente.</div>
                        @if (request()->routeIs('product-parent-backfill.index') && ($totals['unmatched'] ?? 0) > 0)
                            <div class="mt-2">
                                Esti pe pagina Corelate. Pentru produsele necorelate,
                                <a href="{{ route('product-parent-backfill.unmatched', request()->only(['search', 'shop_id'])) }}" class="font-semibold text-blue-600 underline">
                                    deschide pagina Necorelate
                                </a>.
                            </div>
                        @elseif (request()->routeIs('product-parent-backfill.unmatched'))
                            <div class="mt-2">
                                Incearca sa selectezi `Toate necorelate` sau sa resetezi filtrele.
                            </div>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="border-t border-gray-100 px-4 py-4">
    {{ $candidates->links() }}
</div>
