<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GangsheetController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\GangsheetLabelController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\CheckApiKey;
use App\Http\Middleware\CheckRole;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);

// Public API for external sellers (header X-API-KEY: bsk_...).
Route::middleware(CheckApiKey::class)->group(function () {
    // Orders
    Route::post('/fulfill', [OrderController::class, 'fulfillByApiKey']);
    Route::get('/fulfill', [OrderController::class, 'indexByApiKey']);
    Route::get('/fulfill/{idOrRef}', [OrderController::class, 'showByApiKey']);
    // Catalog (tier-scoped)
    Route::get('/catalog/products', [OrderController::class, 'productsByApiKey']);
    Route::get('/catalog/accessories', [OrderController::class, 'accessoriesByApiKey']);
});

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

    // Generic B2 upload proxy for browser SPAs (web-bullstart). Desktop apps
    // upload directly via the Electron main process and skip this.
    Route::post('/uploads/file', [UploadController::class, 'store']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/export-csv-template', [OrderController::class, 'exportCsvTemplate']);
    Route::get('/orders/export', [OrderController::class, 'exportOrders']);
    Route::get('/orders/invoice-data', [OrderController::class, 'invoiceData']);
    Route::post('/orders/import-csv', [OrderController::class, 'importCsv']);
    Route::post('/orders/import-envelopes', [OrderController::class, 'importEnvelopes']);
    Route::post('/orders/bulk-status', [OrderController::class, 'bulkUpdateStatus']);
    Route::post('/orders/bulk-pay', [OrderController::class, 'bulkPay']);
    Route::get('/orders/unpaid-summary', [OrderController::class, 'unpaidSummary']);
    Route::post('/orders/pay-all-unpaid', [OrderController::class, 'payAllUnpaid']);
    Route::post('/orders/bulk-reconvert', [OrderController::class, 'bulkReconvert']);
    Route::post('/orders/bulk-reconvert-label', [OrderController::class, 'bulkReconvertLabel']);
    Route::post('/orders/bulk-delete', [OrderController::class, 'bulkDestroy']);
    Route::post('/orders/bulk-re-production', [OrderController::class, 'bulkReProduction']);
    Route::post('/orders/bulk-set-production', [OrderController::class, 'bulkSetProduction']);
    Route::post('/orders/bulk-assign', [OrderController::class, 'bulkAssign']);
    Route::post('/orders/bulk-merge-duplicates', [OrderController::class, 'bulkMergeDuplicates']);
    Route::post('/orders/refresh-duplicates', [OrderController::class, 'runDuplicateRefresh']);
    Route::get('/orders/pending-tracking', [OrderController::class, 'pendingTracking']);
    Route::post('/orders/{order}/fetch-tracking', [OrderController::class, 'fetchTrackingOne']);
    Route::post('/orders/{order}/save-tracking', [OrderController::class, 'saveTracking']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay']);
    Route::post('/orders/{order}/reconvert', [OrderController::class, 'reconvert']);
    Route::post('/orders/{order}/reconvert-label', [OrderController::class, 'reconvertLabel']);
    Route::put('/order-items/{item}/metas', [OrderController::class, 'updateItemMetas']);
    Route::put('/order-items/{item}/accessories', [OrderController::class, 'updateItemAccessories']);

    // Server-side proxy for fetching convert_label image bytes — bypasses
    // browser CORS when Electron renderer needs to embed labels into a
    // merged PDF. See mergedLabelBuilder.jsx.
    Route::get('/orders/{order}/convert-label-blob', [OrderController::class, 'convertLabelBlob']);

    // Products (read for all)
    Route::get('/products', [ProductController::class, 'index']);
    // Cross-product flat search — useful when seller knows SKU but not product.
    Route::get('/variants', [ProductController::class, 'searchVariants']);
    Route::get('/accessories', [ProductController::class, 'searchAccessories']);
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
    Route::post('/conversion/order/{order}/convert-label', [ConversionController::class, 'uploadConvertLabel']);
    Route::get('/conversion/label-lookup/{idOrSystemId}', [ConversionController::class, 'lookupLabelTarget']);

    // Shared upload credentials for OrderCreate B2 uploads (mockups, shipping
    // label, meta values). Any authenticated user — including sellers — gets
    // the per-day folder so they can upload directly from the desktop app.
    Route::get('/gangsheets/storage-credentials', [GangsheetController::class, 'storageCredentials']);

    // Gangsheet (admin/support only)
    Route::middleware(CheckRole::class . ':admin,support')->group(function () {
        Route::get('/gangsheets/pending-orders', [GangsheetController::class, 'pendingOrders']);
        Route::post('/gangsheets/lookup-orders', [GangsheetController::class, 'lookupOrders']);
        Route::get('/gangsheets', [GangsheetController::class, 'index']);
        Route::post('/gangsheets', [GangsheetController::class, 'store']);
        Route::post('/gangsheets/bulk-delete', [GangsheetController::class, 'bulkDestroy']);
        Route::get('/gangsheets/{gangsheet}', [GangsheetController::class, 'show']);
        Route::delete('/gangsheets/{gangsheet}', [GangsheetController::class, 'destroy']);

        // Gangsheet label — parallel grouping of convert_labels for orders
        // already in a _qr gangsheet. Scanned for bulk complete-all.
        Route::get('/gangsheet-labels', [GangsheetLabelController::class, 'index']);
        Route::post('/gangsheet-labels/merge', [GangsheetLabelController::class, 'merge']);
        Route::post('/gangsheet-labels/bulk-delete', [GangsheetLabelController::class, 'bulkDestroy']);
        Route::get('/gangsheet-labels/{gangsheetLabel}', [GangsheetLabelController::class, 'show']);
        Route::post('/gangsheet-labels/{gangsheetLabel}/complete-all', [GangsheetLabelController::class, 'completeAll']);
        Route::put('/gangsheet-labels/{gangsheetLabel}/file-url', [GangsheetLabelController::class, 'updateFileUrl']);
        Route::delete('/gangsheet-labels/{gangsheetLabel}', [GangsheetLabelController::class, 'destroy']);
    });

    // VNPay (authenticated — seller initiates a deposit)
    Route::get('/wallet/vnpay/rate', [\App\Http\Controllers\VnpayController::class, 'rate']);
    Route::post('/wallet/vnpay/create', [\App\Http\Controllers\VnpayController::class, 'create']);

    // Wallet
    Route::get('/wallet/balance', [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::get('/wallet/transactions/export', [WalletController::class, 'exportTransactions']);
    // Deposit submit + edit are accessible to seller (own pending only) and admin.
    Route::post('/wallet/deposit', [WalletController::class, 'deposit']);
    Route::put('/wallet/deposit/{transaction}', [WalletController::class, 'updateDeposit']);
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::post('/wallet/deposit/{transaction}/approve', [WalletController::class, 'approveDeposit']);
        Route::post('/wallet/refund', [WalletController::class, 'refund']);
    });

    // Inventory (admin only) — manual stock imports. Shipping orders also
    // decrements stock automatically (see Order::decrementStockForShipment).
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::get('/inventory/stock', [InventoryController::class, 'stock']);
        Route::post('/inventory/resync', [InventoryController::class, 'resyncStock']);
        Route::get('/inventory/imports/template', [InventoryController::class, 'exportTemplate']);
        Route::post('/inventory/imports/bulk', [InventoryController::class, 'importCsv']);
        Route::get('/inventory/imports', [InventoryController::class, 'index']);
        Route::post('/inventory/imports', [InventoryController::class, 'store']);
        Route::delete('/inventory/imports/{import}', [InventoryController::class, 'destroy']);
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

        Route::get('/invoice-payment',  [SettingController::class, 'getInvoicePayment']);
        Route::put('/invoice-payment',  [SettingController::class, 'updateInvoicePayment']);

        Route::get('/telegram',         [TelegramController::class, 'getSettings']);
        Route::put('/telegram',         [TelegramController::class, 'updateSettings']);
        Route::get('/telegram/groups',  [TelegramController::class, 'discoverGroups']);
        Route::post('/telegram/test',   [TelegramController::class, 'test']);
        Route::post('/telegram/report', [TelegramController::class, 'report']);
    });
});
