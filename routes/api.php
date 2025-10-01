<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

// Auth
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// Public read-only routes
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{category}', [CategoryController::class, 'show']);

Route::get('products', [ProductController::class, 'index']);
Route::get('products/{product}', [ProductController::class, 'show']);


/*
|--------------------------------------------------------------------------
| Protected routes (requires authentication)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('me', [AuthController::class, 'me']);
        Route::post('profile/update', [AuthController::class, 'update']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Categories - only admin can create/update/delete
    Route::middleware('can:manage-categories')->group(function () {
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
    });

    // Products - only admin can create/update/delete
    Route::middleware('can:manage-products')->group(function () {
        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
    });

    // Cart (for authenticated users)
    Route::apiResource('carts', CartController::class);

    // Orders (for authenticated users)
    Route::apiResource('orders', OrderController::class)->except(['store', 'update']);

    Route::prefix('checkout')->group(function () {
        Route::post('/summary', [CheckoutController::class, 'getCheckoutSummary']);
        Route::post('/process', [CheckoutController::class, 'processCheckout']);
        Route::post('/confirm-payment', [CheckoutController::class, 'confirmStripePayment']);
    });
});
