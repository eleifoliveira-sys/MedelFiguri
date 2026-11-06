<?php

use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/docs', function () {
    return response()->view('api-docs');
});

Route::get('/docs/openapi.yaml', function () {
    return response()->file(base_path('openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
});

Route::get('/docs/assets/{asset}', function (string $asset) {
    $assetPath = base_path('node_modules/swagger-ui-dist/'.$asset);
    if (!is_file($assetPath)) {
        $assetPath = '/opt/swagger-ui/'.$asset;
    }

    $contentTypes = [
        'swagger-ui.css' => 'text/css; charset=UTF-8',
        'swagger-ui-bundle.js' => 'application/javascript',
        'swagger-ui-standalone-preset.js' => 'application/javascript',
    ];

    return response()->file($assetPath, [
        'Content-Type' => $contentTypes[$asset],
    ]);
})->where('asset', 'swagger-ui\.css|swagger-ui-bundle\.js|swagger-ui-standalone-preset\.js');

Route::post('/register', [AuthController::class, 'register'])->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:5,1']);

Route::get('/categories', [CatalogController::class, 'categories']);
Route::get('/products', [CatalogController::class, 'products']);
Route::get('/products/{product:slug}', [CatalogController::class, 'show']);

Route::get('/cart', [CartController::class, 'show']);
Route::post('/cart/items', [CartController::class, 'addItem']);
Route::patch('/cart/items/{id}', [CartController::class, 'updateItem'])
    ->whereNumber('id');
Route::delete('/cart/items/{id}', [CartController::class, 'removeItem'])
    ->whereNumber('id');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/checkout', [CheckoutController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
    Route::post('/orders/{id}/confirm-payment', [OrderController::class, 'confirmPayment'])
        ->whereNumber('id');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id');
    Route::get('/cart/guest', [CartController::class, 'showGuest']);
    Route::patch('/cart/guest/items/{id}', [CartController::class, 'updateGuestItem'])
        ->whereNumber('id');
    Route::delete('/cart/guest/items/{id}', [CartController::class, 'removeGuestItem'])
        ->whereNumber('id');
    Route::post('/cart/merge-guest', [CartController::class, 'mergeGuest']);

    Route::get('/admin/me', [AuthController::class, 'adminMe'])
        ->middleware('role:admin');

    Route::prefix('/admin')->middleware('role:admin')->group(function (): void {
        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::get('/products/{id}', [AdminProductController::class, 'show'])->whereNumber('id');
        Route::patch('/products/{id}', [AdminProductController::class, 'update'])->whereNumber('id');
        Route::delete('/products/{id}', [AdminProductController::class, 'destroy'])->whereNumber('id');
        Route::post('/products/{productId}/images', [AdminProductController::class, 'storeImage'])
            ->whereNumber('productId');
        Route::delete('/products/{productId}/images/{imageId}', [AdminProductController::class, 'destroyImage'])
            ->whereNumber('productId')
            ->whereNumber('imageId');
        Route::post('/products/{productId}/stock-adjustments', [AdminInventoryController::class, 'adjust'])
            ->whereNumber('productId');
        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::get('/categories/{id}', [AdminCategoryController::class, 'show'])->whereNumber('id');
        Route::patch('/categories/{id}', [AdminCategoryController::class, 'update'])->whereNumber('id');
        Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy'])->whereNumber('id');
        Route::get('/inventory/movements', [AdminInventoryController::class, 'index']);
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id');
        Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus'])
            ->whereNumber('id');
    });
});
