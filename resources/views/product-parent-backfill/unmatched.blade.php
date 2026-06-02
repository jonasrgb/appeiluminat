<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Produse necorelate parentproduct
            </h2>
            <div class="text-sm text-gray-500">
                Ambigue: {{ $totals['ambiguous'] }} | De verificat: {{ $totals['unmatched'] }}
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @php
                $tabQuery = request()->only(['search', 'shop_id']);
            @endphp
            <div class="mb-4 flex flex-wrap gap-2">
                <a href="{{ route('product-parent-backfill.index', $tabQuery) }}" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50">
                    Afiseaza corelate {{ $totals['correlated'] }}
                </a>
                <a href="{{ route('product-parent-backfill.unmatched', $tabQuery) }}" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white">
                    Afiseaza necorelate {{ $totals['unmatched'] }}
                </a>
            </div>

            <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-100">
                Afisezi acum produsele necorelate sau ambigue. Acestea nu au fost modificate cu `--apply` si trebuie verificate manual.
            </div>

            @include('product-parent-backfill.partials.filters')

            <div class="mt-4 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                @include('product-parent-backfill.partials.table', ['candidates' => $candidates])
            </div>
        </div>
    </div>
</x-app-layout>
