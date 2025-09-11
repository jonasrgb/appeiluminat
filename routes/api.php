<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopifyOrderController;
use App\Http\Controllers\ShopifyWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['throttle:10,1'])
    ->post('/shopify/{store}/order-status', [ShopifyOrderController::class, 'getOrderStatus']);

Route::post('/webhooks/shopify', [ShopifyWebhookController::class, 'handle'])
    ->middleware('shopify.webhook:products/create');


Route::post('/webhooks/shopify/update', [ShopifyWebhookController::class, 'handle'])
    ->middleware('shopify.webhook:products/update');