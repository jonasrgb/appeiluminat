<form method="GET" action="{{ url()->current() }}" class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
    <div class="grid gap-4 {{ isset($sortOptions) ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }}">
    <div>
        <label for="search" class="block text-xs font-medium text-gray-600">Cauta</label>
        <input
            id="search"
            name="search"
            type="search"
            value="{{ $filters['search'] ?? '' }}"
            placeholder="ID, titlu sau handle"
            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        >
    </div>

    <div>
        <label for="shop_id" class="block text-xs font-medium text-gray-600">Magazin</label>
        <select
            id="shop_id"
            name="shop_id"
            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        >
            <option value="">Toate magazinele</option>
            @foreach ($shops as $shop)
                <option value="{{ $shop->id }}" @selected((string)($filters['shop_id'] ?? '') === (string)$shop->id)>
                    {{ $shop->name ?: $shop->domain }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="status" class="block text-xs font-medium text-gray-600">Status corelare</label>
        <select
            id="status"
            name="status"
            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        >
            @foreach ($statusOptions as $value => $label)
                <option value="{{ $value }}" @selected((string)($filters['status'] ?? '') === (string)$value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>

    @isset($sortOptions)
        <div>
            <label for="sort" class="block text-xs font-medium text-gray-600">Sortare</label>
            <select
                id="sort"
                name="sort"
                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                @foreach ($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected((string)($filters['sort'] ?? 'last_scanned_desc') === (string)$value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
    @endisset

    <div class="flex items-end gap-2">
        <button type="submit" class="inline-flex h-10 items-center rounded-md bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">
            Filtreaza
        </button>
        <a href="{{ url()->current() }}" class="inline-flex h-10 items-center rounded-md bg-white px-4 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50">
            Reset
        </a>
    </div>
    </div>
</form>
