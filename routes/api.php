<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPaymentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

// Stripe Webhook (must be outside auth middleware)
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handleWebhook']);

// Auth
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Password reset (public routes)
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('verify-reset-code', [AuthController::class, 'verifyResetCode']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
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
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile/update', [AuthController::class, 'update']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Email verification
        Route::post('send-verification-code', [AuthController::class, 'sendVerificationCode']);
        Route::post('verify-email', [AuthController::class, 'verifyEmail']);
        Route::post('resend-verification-code', [AuthController::class, 'resendVerificationCode']);

        // Password management
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });

    // Cart (for authenticated users)
    Route::get('carts', [CartController::class, 'index']);
    Route::post('carts', [CartController::class, 'store']);
    Route::put('carts', [CartController::class, 'update']);
    Route::delete('carts', [CartController::class, 'destroy']);

    // Orders (for authenticated users)
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);

    // Payments (for authenticated users)
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);

    // Checkout
    Route::prefix('checkout')->group(function () {
        Route::get('/summary', [CheckoutController::class, 'getCheckoutSummary']);
        Route::post('/process', [CheckoutController::class, 'processCheckout']);
        Route::post('/verify-payment', [CheckoutController::class, 'verifyPayment']);
    });

    /*
    |--------------------------------------------------------------------------
    | Admin routes (requires admin role)
    |--------------------------------------------------------------------------
    */
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {

        // Dashboard & Statistics
        Route::get('dashboard', [AdminDashboardController::class, 'index']);
        Route::get('dashboard/sales-chart', [AdminDashboardController::class, 'salesChart']);

        // User Management
        Route::apiResource('users', UserController::class);

        // Category Management
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

        // Product Management
        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);

        // Order Management
        Route::prefix('orders')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index']);
            Route::post('/', [AdminOrderController::class, 'store']); // Create new order
            Route::get('/statistics', [AdminOrderController::class, 'statistics']);
            Route::get('/{order}', [AdminOrderController::class, 'show']);
            Route::put('/{order}', [AdminOrderController::class, 'update']);
            Route::delete('/{order}', [AdminOrderController::class, 'destroy']);

            // Order actions
            Route::post('/{order}/mark-as-paid', [AdminOrderController::class, 'markAsPaid']);
            Route::post('/{order}/mark-as-unpaid', [AdminOrderController::class, 'markAsUnpaid']);
            Route::post('/{order}/cancel', [AdminOrderController::class, 'cancelOrder']);

            // Payment management for specific order
            Route::get('/{order}/payment', [AdminOrderController::class, 'getPayment']);
            Route::post('/{order}/payment', [AdminOrderController::class, 'updatePayment']);
            Route::post('/{order}/payment/refund', [AdminOrderController::class, 'refundPayment']);
        });

        // Payment Management (all payments)
        Route::get('payments', [AdminOrderController::class, 'getAllPayments']);
    });
});
