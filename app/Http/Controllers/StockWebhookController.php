<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStockWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StockWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $expectedToken = (string) env('STOCK_API_TOKEN', '');
        $receivedToken = (string) ($request->bearerToken() ?: $request->header('X-Api-Token', ''));

        if ($expectedToken === '') {
            Log::error('Stock endpoint token is not configured');
            return response()->json(['message' => 'Stock token not configured'], 500);
        }

        if ($receivedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $payload = $request->validate([
            'id' => ['required'],
            'title' => ['required', 'string'],
            'handle' => ['required', 'string'],
            'sku' => ['nullable', 'string'],
        ]);

        if (($payload['sku'] ?? '') === 'cod-fee') {
            return response()->json(['status' => 'skipped'], 200);
        }

        ProcessStockWebhook::dispatch($payload)->onQueue('stock');

        Log::info('Stock webhook queued', [
            'id' => (string) $payload['id'],
            'sku' => (string) $payload['sku'],
        ]);

        return response()->json(['status' => 'queued'], 202);
    }
}
