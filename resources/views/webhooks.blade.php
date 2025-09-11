<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Webhook Logs') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <table class="min-w-full border border-gray-300 mt-4">
                        <thead class="bg-gray-700 text-white">
                            <tr>
                                <th class="border px-4 py-2">Topic</th>
                                <th class="border px-4 py-2">Webhook ID</th>
                                <th class="border px-4 py-2">Received At</th>
                                <th class="border px-4 py-2">Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($webhooks as $webhook)
                                <tr class="border">
                                    <td class="border px-4 py-2">{{ $webhook->topic }}</td>
                                    <td class="border px-4 py-2">{{ $webhook->webhook_id }}</td>
                                    <td class="border px-4 py-2">{{ $webhook->created_at->toDateTimeString() }}</td>
                                    <td class="border px-4 py-2">
                                        <a href="{{ route('webhooks.show', $webhook->id) }}" class="text-blue-500">View JSON</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    {{ $webhooks->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
