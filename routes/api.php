<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GangsheetController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\CheckApiKey;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);

// Fulfill via API key (for sellers to POST orders externally)
Route::post('/fulfill', [OrderController::class, 'fulfillByApiKey'])->middleware(CheckApiKey::class);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::get('/me/api-key', [AuthController::class, 'myApiKey']);
    Route::post('/me/regenerate-api-key', [AuthController::class, 'regenerateMyApiKey']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/export-csv-template', [OrderController::class, 'exportCsvTemplate']);
    Route::get('/orders/export', [OrderController::class, 'exportOrders']);
    Route::post('/orders/import-csv', [OrderController::class, 'importCsv']);
    Route::post('/orders/bulk-status', [OrderController::class, 'bulkUpdateStatus']);
    Route::post('/orders/bulk-pay', [OrderController::class, 'bulkPay']);
    Route::post('/orders/bulk-reconvert', [OrderController::class, 'bulkReconvert']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay']);
    Route::post('/orders/{order}/reconvert', [OrderController::class, 'reconvert']);

    // Products (read for all)
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::get('/products/{product}/variants', [ProductController::class, 'variants']);
    Route::get('/products/{product}/prices', [ProductController::class, 'prices']);

    // Products (admin write)
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
        Route::post('/products/{product}/variants/bulk', [ProductController::class, 'bulkStoreVariants']);
        Route::put('/variants/{variant}', [ProductController::class, 'updateVariant']);
        Route::delete('/variants/{variant}', [ProductController::class, 'destroyVariant']);
        Route::post('/product-prices/import', [ProductController::class, 'importPrices']);
        Route::post('/product-prices/import-csv', [ProductController::class, 'importPricesCsv']);
        Route::get('/product-prices/export-template', [ProductController::class, 'exportPriceTemplate']);
        Route::get('/product-prices/export', [ProductController::class, 'exportPrices']);

        // Accessories
        Route::post('/products/{product}/accessories', [ProductController::class, 'storeAccessory']);
        Route::put('/accessories/{accessory}', [ProductController::class, 'updateAccessory']);
        Route::delete('/accessories/{accessory}', [ProductController::class, 'destroyAccessory']);
        Route::post('/accessories/{accessory}/prices', [ProductController::class, 'storeAccessoryPrice']);
        Route::put('/accessory-prices/{price}', [ProductController::class, 'updateAccessoryPrice']);
        Route::delete('/accessory-prices/{price}', [ProductController::class, 'destroyAccessoryPrice']);
    });

    // Conversion (only for users with convert=1)
    Route::get('/conversion/pending', [ConversionController::class, 'pending']);
    Route::post('/conversion/{item}/qr-meta', [ConversionController::class, 'uploadQrMeta']);

    // Gangsheet (admin/support only)
    Route::middleware(CheckRole::class . ':admin,support')->group(function () {
        Route::get('/gangsheets/pending-orders', [GangsheetController::class, 'pendingOrders']);
        Route::post('/gangsheets/lookup-orders', [GangsheetController::class, 'lookupOrders']);
        Route::get('/gangsheets', [GangsheetController::class, 'index']);
        Route::post('/gangsheets', [GangsheetController::class, 'store']);
        Route::get('/gangsheets/{gangsheet}', [GangsheetController::class, 'show']);
        Route::delete('/gangsheets/{gangsheet}', [GangsheetController::class, 'destroy']);
    });

    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::post('/wallet/deposit', [WalletController::class, 'deposit']);
        Route::post('/wallet/refund', [WalletController::class, 'refund']);
    });

    // Users (admin only)
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::post('/users/{user}/regenerate-api-key', [UserController::class, 'regenerateApiKey']);
        Route::get('/users/{user}/api-key', [UserController::class, 'getApiKey']);
    });

    // Settings (admin only)
    Route::middleware(CheckRole::class . ':admin')->prefix('settings')->group(function () {
        Route::get('/roles', [SettingController::class, 'roles']);
        Route::post('/roles', [SettingController::class, 'storeRole']);
        Route::put('/roles/{role}', [SettingController::class, 'updateRole']);
        Route::delete('/roles/{role}', [SettingController::class, 'destroyRole']);
        Route::put('/roles/{role}/permissions', [SettingController::class, 'updatePermissions']);
        Route::get('/modules', [SettingController::class, 'modules']);

        Route::get('/tiers', [SettingController::class, 'tiers']);
        Route::post('/tiers', [SettingController::class, 'storeTier']);
        Route::put('/tiers/{tier}', [SettingController::class, 'updateTier']);
        Route::delete('/tiers/{tier}', [SettingController::class, 'destroyTier']);
    });
});
