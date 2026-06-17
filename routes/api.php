<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GangsheetController;
use App\Http\Controllers\GangsheetGroupController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryForecastController;
use App\Http\Controllers\GangsheetLabelController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use App\Http\Middleware\CheckApiKey;
use App\Http\Controllers\ReasonController;
use App\Http\Controllers\ReprintController;
use App\Http\Controllers\TrackingController;
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
    Route::get('/help',         [\App\Http\Controllers\HelpController::class, 'index']);
    Route::get('/help/{slug}',  [\App\Http\Controllers\HelpController::class, 'show']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::get('/me/api-key', [AuthController::class, 'myApiKey']);
    Route::post('/me/regenerate-api-key', [AuthController::class, 'regenerateMyApiKey']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/shipped-report', [DashboardController::class, 'shippedReport']);

    // Stamp shipping config — any auth user can read (OrderCreate fee preview).
    Route::get('/settings/stamp-config', [SettingController::class, 'getStampConfig']);
    // Resend config — readable by any auth user (resend modal methods/reasons).
    Route::get('/settings/resend-config', [SettingController::class, 'getResendConfig']);

    // Generic B2 upload proxy for browser SPAs (web-bullstart). Desktop apps
    // upload directly via the Electron main process and skip this.
    Route::post('/uploads/file', [UploadController::class, 'store']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/export-csv-template', [OrderController::class, 'exportCsvTemplate']);
    Route::get('/orders/export-stamp-csv-template', [OrderController::class, 'exportStampCsvTemplate']);
    Route::get('/orders/export', [OrderController::class, 'exportOrders']);
    Route::get('/orders/invoice-data', [OrderController::class, 'invoiceData']);
    Route::post('/orders/import-csv', [OrderController::class, 'importCsv']);
    Route::post('/orders/import-envelopes', [OrderController::class, 'importEnvelopes']);
    Route::post('/orders/bulk-status', [OrderController::class, 'bulkUpdateStatus']);
    Route::post('/orders/bulk-pay', [OrderController::class, 'bulkPay']);
    Route::get('/orders/unpaid-summary', [OrderController::class, 'unpaidSummary']);
    Route::post('/orders/pay-all-unpaid', [OrderController::class, 'payAllUnpaid']);
    Route::post('/orders/auto-pay/run', [OrderController::class, 'autoPayRun']);
    Route::get('/orders/auto-pay/status', [OrderController::class, 'autoPayStatus']);
    Route::post('/orders/auto-pay/run-for/{user}', [OrderController::class, 'autoPayRunFor']);
    Route::post('/orders/bulk-reconvert', [OrderController::class, 'bulkReconvert']);
    Route::post('/orders/bulk-reconvert-label', [OrderController::class, 'bulkReconvertLabel']);
    Route::post('/orders/bulk-delete', [OrderController::class, 'bulkDestroy']);
    Route::post('/orders/bulk-re-production', [OrderController::class, 'bulkReProduction']);
    Route::post('/orders/bulk-set-production', [OrderController::class, 'bulkSetProduction']);
    Route::post('/orders/bulk-assign', [OrderController::class, 'bulkAssign']);
    Route::post('/orders/bulk-merge-duplicates', [OrderController::class, 'bulkMergeDuplicates']);
    Route::post('/orders/refresh-duplicates', [OrderController::class, 'runDuplicateRefresh']);
    Route::get('/orders/pending-tracking', [OrderController::class, 'pendingTracking']);
    Route::get('/orders/resend-quota', [OrderController::class, 'resendQuota']);
    Route::post('/orders/{order}/fetch-tracking', [OrderController::class, 'fetchTrackingOne']);
    Route::post('/orders/{order}/save-tracking', [OrderController::class, 'saveTracking']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::put('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::post('/orders/{order}/pay', [OrderController::class, 'pay']);
    Route::post('/orders/{order}/resend-preview', [OrderController::class, 'resendPreview']);
    Route::post('/orders/{order}/resend', [OrderController::class, 'storeResend']);
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

    // Materials — public read (any authenticated user). Writes are admin-only
    // and live in the admin group below.
    Route::get('/products/{product}/materials', [ProductController::class, 'listMaterials']);
    Route::get('/materials/{material}', [ProductController::class, 'showMaterial']);

    // Products (admin write)
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate']);
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

        // Materials (chất liệu) — per-product
        Route::post('/products/{product}/materials', [ProductController::class, 'storeMaterial']);
        Route::put('/materials/{material}', [ProductController::class, 'updateMaterial']);
        Route::delete('/materials/{material}', [ProductController::class, 'destroyMaterial']);
    });

    // Delivery tracking (ShipEngine) — config + manual control (admin only).
    Route::middleware(CheckRole::class . ':admin')->prefix('tracking')->group(function () {
        Route::get('/settings', [TrackingController::class, 'settings']);
        Route::put('/settings', [TrackingController::class, 'updateSettings']);
        Route::get('/stats', [TrackingController::class, 'stats']);
        Route::post('/run', [TrackingController::class, 'run']);
        Route::post('/check', [TrackingController::class, 'checkSelected']);
        Route::get('/shipengine-key', [TrackingController::class, 'shipengineKey']);
        Route::put('/shipengine-key', [TrackingController::class, 'updateShipengineKey']);
    });

    // Reprints (user-scoped) + reasons. Reason writes are admin-only; reading
    // reasons + all reprint ops are available to any authenticated user (the
    // controller scopes reprints to the owner).
    Route::get('/reasons', [ReasonController::class, 'index']);
    Route::get('/reasons/by-code/{code}', [ReasonController::class, 'byCode']);
    Route::get('/reprints', [ReprintController::class, 'index']);
    Route::post('/reprints', [ReprintController::class, 'store']);
    Route::post('/reprints/mark-gangsheet', [ReprintController::class, 'markGangsheet']);
    Route::put('/reprints/{reprint}', [ReprintController::class, 'update']);
    Route::delete('/reprints/{reprint}', [ReprintController::class, 'destroy']);
    // Partners (partner-bullstart) can add their own reasons; rename/delete stay
    // admin-only since reasons are shared global config.
    Route::middleware(CheckRole::class . ':admin,partner')
        ->post('/reasons', [ReasonController::class, 'store']);
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::put('/reasons/{reason}', [ReasonController::class, 'update']);
        Route::delete('/reasons/{reason}', [ReasonController::class, 'destroy']);
    });

    // Conversion (only for users with convert=1)
    Route::get('/conversion/pending', [ConversionController::class, 'pending']);
    Route::post('/conversion/resize-targets', [ConversionController::class, 'resizeTargets']);
    Route::post('/conversion/{item}/qr-meta', [ConversionController::class, 'uploadQrMeta']);
    Route::post('/conversion/order/{order}/convert-label', [ConversionController::class, 'uploadConvertLabel']);
    Route::get('/conversion/label-lookup/{idOrSystemId}', [ConversionController::class, 'lookupLabelTarget']);

    // QR background check (in-app cron): find/record/list not-shipped orders
    // whose front_qr design has a dark background in the barcode area.
    Route::get('/conversion/qr-bg/pending', [ConversionController::class, 'qrBgPending']);
    Route::post('/conversion/qr-bg/result', [ConversionController::class, 'qrBgResult']);
    Route::get('/conversion/qr-bg/flagged', [ConversionController::class, 'qrBgFlagged']);
    Route::post('/conversion/qr-bg/notify', [ConversionController::class, 'qrBgNotify']);

    // Shared upload credentials for OrderCreate B2 uploads (mockups, shipping
    // label, meta values). Any authenticated user — including sellers — gets
    // the per-day folder so they can upload directly from the desktop app.
    Route::get('/gangsheets/storage-credentials', [GangsheetController::class, 'storageCredentials']);

    // Gangsheet (admin/support only)
    Route::middleware(CheckRole::class . ':admin,support')->group(function () {
        Route::get('/gangsheets/pending-orders', [GangsheetController::class, 'pendingOrders']);
        Route::post('/gangsheets/lookup-orders', [GangsheetController::class, 'lookupOrders']);
        // Partner assignment (admin assigns gangs to partner users).
        Route::get('/gangsheets/partner-users', [GangsheetController::class, 'partnerUsers']);
        Route::put('/gangsheets/{gangsheet}/partners', [GangsheetController::class, 'setPartners']);
        Route::get('/gangsheets', [GangsheetController::class, 'index']);
        Route::post('/gangsheets', [GangsheetController::class, 'store']);
        Route::post('/gangsheets/bulk-delete', [GangsheetController::class, 'bulkDestroy']);
        Route::post('/gangsheets/{gangsheet}/toggle-downloaded', [GangsheetController::class, 'toggleDownloaded']);
        Route::get('/gangsheets/{gangsheet}', [GangsheetController::class, 'show']);
        Route::delete('/gangsheets/{gangsheet}', [GangsheetController::class, 'destroy']);

        // Automation gangsheet groups (doc/automation_gangsheet.md). All driven
        // by the desktop app — no server scheduler. Static paths before the
        // wildcard {group} so they don't get swallowed by route binding.
        Route::get('/gangsheet-groups/automation-config', [GangsheetGroupController::class, 'getAutomationConfig']);
        Route::get('/gangsheet-groups/assign-user-options', [GangsheetGroupController::class, 'assignUserOptions']);
        Route::put('/gangsheet-groups/automation-config', [GangsheetGroupController::class, 'updateAutomationConfig']);
        Route::post('/gangsheet-groups/assign', [GangsheetGroupController::class, 'assign']);
        Route::post('/gangsheet-groups/claim', [GangsheetGroupController::class, 'claim']);
        Route::post('/gangsheet-groups/release-stale', [GangsheetGroupController::class, 'releaseStale']);
        Route::post('/gangsheet-groups/mark-fired', [GangsheetGroupController::class, 'markFired']);
        Route::post('/gangsheet-groups/delete-open', [GangsheetGroupController::class, 'deleteOpen']);
        Route::get('/gangsheet-groups', [GangsheetGroupController::class, 'index']);
        Route::get('/gangsheet-groups/{group}', [GangsheetGroupController::class, 'show']);
        Route::post('/gangsheet-groups/{group}/finalize', [GangsheetGroupController::class, 'finalize']);
        Route::post('/gangsheet-groups/{group}/remove-design', [GangsheetGroupController::class, 'removeDesign']);
        Route::delete('/gangsheet-groups/{group}', [GangsheetGroupController::class, 'destroy']);

        // Gangsheet label — parallel grouping of convert_labels for orders
        // already in a _qr gangsheet. Scanned for bulk complete-all.
        Route::get('/gangsheet-labels', [GangsheetLabelController::class, 'index']);
        Route::post('/gangsheet-labels/merge', [GangsheetLabelController::class, 'merge']);
        Route::post('/gangsheet-labels/merge-by-system-ids', [GangsheetLabelController::class, 'mergeBySystemIds']);
        Route::post('/gangsheet-labels/bulk-delete', [GangsheetLabelController::class, 'bulkDestroy']);
        Route::get('/gangsheet-labels/{gangsheetLabel}', [GangsheetLabelController::class, 'show']);
        Route::post('/gangsheet-labels/{gangsheetLabel}/complete-all', [GangsheetLabelController::class, 'completeAll']);
        Route::put('/gangsheet-labels/{gangsheetLabel}/file-url', [GangsheetLabelController::class, 'updateFileUrl']);
        Route::delete('/gangsheet-labels/{gangsheetLabel}', [GangsheetLabelController::class, 'destroy']);
    });

    // Partner app (partner-bullstart) — partner sees only assigned gangsheets.
    // Admin allowed too for testing/support.
    Route::middleware(CheckRole::class . ':partner,admin')->prefix('partner')->group(function () {
        Route::get('/dashboard', [PartnerController::class, 'dashboard']);
        Route::get('/gangsheets', [PartnerController::class, 'gangsheets']);
        Route::post('/gangsheets/{gangsheet}/printed', [PartnerController::class, 'markPrinted']);
        Route::get('/gangsheets/{gangsheet}', [PartnerController::class, 'show']);
        // Compose + reconvert, scoped to orders inside the partner's assigned gangs.
        Route::get('/orders', [PartnerController::class, 'myOrders']);
        Route::get('/storage-credentials', [PartnerController::class, 'storageCredentials']);
        Route::post('/gangsheets', [PartnerController::class, 'storeGangsheet']);
        Route::post('/orders/reconvert', [PartnerController::class, 'reconvert']);
    });

    // VNPay (authenticated — seller initiates a deposit)
    Route::get('/wallet/vnpay/rate', [\App\Http\Controllers\VnpayController::class, 'rate']);
    Route::post('/wallet/vnpay/create', [\App\Http\Controllers\VnpayController::class, 'create']);

    // Bank-transfer top-up (manual approval — used while real VNPay isn't live)
    Route::get('/wallet/bank-transfer/rate', [\App\Http\Controllers\BankTransferController::class, 'rate']);
    Route::post('/wallet/bank-transfer/init', [\App\Http\Controllers\BankTransferController::class, 'init']);
    Route::post('/wallet/bank-transfer/confirm', [\App\Http\Controllers\BankTransferController::class, 'confirm']);

    // PingPong wire top-up (USD) — same 2-step flow as bank-transfer
    Route::get('/wallet/pingpong/info', [\App\Http\Controllers\PingpongTransferController::class, 'rate']);
    Route::post('/wallet/pingpong/init', [\App\Http\Controllers\PingpongTransferController::class, 'init']);
    Route::post('/wallet/pingpong/confirm', [\App\Http\Controllers\PingpongTransferController::class, 'confirm']);

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
        Route::post('/wallet/transactions/bulk-refund', [WalletController::class, 'bulkRefund']);
        Route::post('/wallet/transactions/bulk-refund-delete', [WalletController::class, 'bulkRefundDelete']);
    });

    // Inventory (admin only) — manual stock imports. Shipping orders also
    // decrements stock automatically (see Order::decrementStockForShipment).
    Route::middleware(CheckRole::class . ':admin')->group(function () {
        Route::get('/inventory/stock', [InventoryController::class, 'stock']);
        Route::put('/inventory/stock', [InventoryController::class, 'setStockValue']);
        Route::post('/inventory/resync', [InventoryController::class, 'resyncStock']);
        Route::get('/inventory/imports/template', [InventoryController::class, 'exportTemplate']);
        Route::post('/inventory/imports/bulk', [InventoryController::class, 'importCsv']);
        Route::get('/inventory/imports', [InventoryController::class, 'index']);
        Route::post('/inventory/imports', [InventoryController::class, 'store']);
        Route::delete('/inventory/imports/{import}', [InventoryController::class, 'destroy']);

        // AI forecast + purchase orders
        Route::get('/inventory/forecast', [InventoryForecastController::class, 'forecast']);
        Route::get('/inventory/usage-daily', [InventoryForecastController::class, 'usageDaily']);
        Route::post('/inventory/forecast/ai', [InventoryForecastController::class, 'aiForecast']);
        Route::get('/inventory/ai-config', [InventoryForecastController::class, 'getAiConfig']);
        Route::put('/inventory/ai-config', [InventoryForecastController::class, 'updateAiConfig']);
        Route::get('/inventory/order-items', [InventoryForecastController::class, 'orderItems']);
        Route::put('/inventory/order-items/link', [InventoryForecastController::class, 'saveItemLink']);
        Route::get('/inventory/purchase-orders', [InventoryForecastController::class, 'poIndex']);
        Route::post('/inventory/purchase-orders', [InventoryForecastController::class, 'poStore']);
        Route::post('/inventory/purchase-orders/{po}/approve', [InventoryForecastController::class, 'poApprove']);
        Route::post('/inventory/purchase-orders/{po}/receive', [InventoryForecastController::class, 'poReceive']);
        Route::delete('/inventory/purchase-orders/{po}', [InventoryForecastController::class, 'poDestroy']);
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

        Route::get('/vnpay-merchant', [SettingController::class, 'getVnpayMerchant']);
        Route::put('/vnpay-merchant', [SettingController::class, 'updateVnpayMerchant']);
        Route::put('/stamp-config', [SettingController::class, 'updateStampConfig']);
        Route::put('/resend-config', [SettingController::class, 'updateResendConfig']);

        Route::get('/qr-config', [SettingController::class, 'getQrConfig']);
        Route::put('/qr-config', [SettingController::class, 'updateQrConfig']);

        Route::get('/telegram',         [TelegramController::class, 'getSettings']);
        Route::put('/telegram',         [TelegramController::class, 'updateSettings']);
        Route::get('/bank-transfer',    [\App\Http\Controllers\BankTransferController::class, 'getSettings']);
        Route::put('/bank-transfer',    [\App\Http\Controllers\BankTransferController::class, 'updateSettings']);
        Route::get('/telegram/groups',  [TelegramController::class, 'discoverGroups']);
        Route::post('/telegram/test',   [TelegramController::class, 'test']);
        Route::post('/telegram/report', [TelegramController::class, 'report']);
    });
});
