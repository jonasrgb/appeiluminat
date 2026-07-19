<?php

use App\Http\Controllers\CatalogAuditController;
use App\Http\Controllers\ProductParentBackfillController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [WebhookController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard/catalog-audit/{shop}/missing-images', [CatalogAuditController::class, 'missingImages'])
        ->name('catalog-audit.missing-images');
    Route::get('/dashboard/catalog-audit/{shop}/duplicate-skus', [CatalogAuditController::class, 'duplicateSkus'])
        ->name('catalog-audit.duplicate-skus');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard/product-parent-backfill', [ProductParentBackfillController::class, 'index'])
        ->name('product-parent-backfill.index');
    Route::get('/dashboard/product-parent-backfill/unmatched', [ProductParentBackfillController::class, 'unmatched'])
        ->name('product-parent-backfill.unmatched');
    Route::get('/dashboard/product-parent-backfill/duplicates', [ProductParentBackfillController::class, 'duplicates'])
        ->name('product-parent-backfill.duplicates');
    Route::get('/dashboard/duplicates/{shop}', [ProductParentBackfillController::class, 'duplicatesByShop'])
        ->name('product-parent-duplicates.shop');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
