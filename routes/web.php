<?php

use App\Http\Controllers\VnpayController;
use App\Http\Controllers\Web\GangsheetLabelWebController;
use App\Http\Controllers\Web\QrController;
use App\Http\Controllers\Web\WebAuthController;
use Illuminate\Support\Facades\Route;

// VNPay public endpoints — no auth (VNPay calls these without a session).
Route::any('/wallet/vnpay/ipn', [VnpayController::class, 'ipn']);
Route::get('/wallet/vnpay/return', [VnpayController::class, 'return'])->name('vnpay.return');

// Public landing page. Etsy reviewers visit this URL when evaluating the API
// application — it must be reachable without authentication and must carry
// the Etsy attribution disclaimer (Section 6 of Etsy's API Terms of Use).
Route::get('/', function () {
    return auth()->check()
        ? redirect('/home')
        : view('landing');
})->name('landing');

// Public legal pages — linked from the landing footer and required by Etsy's
// API Terms of Use (privacy policy + terms of service must be reachable).
Route::view('/privacy-policy', 'privacy')->name('privacy');
Route::view('/terms-of-service', 'terms')->name('terms');

// Web auth (session-based) for the QR detail page
Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login']);
});

Route::middleware(['auth', 'web.portal'])->group(function () {
    Route::get('/home', function () {
        return response('<div style="font-family:sans-serif;padding:40px;text-align:center"><h2>BullStart QR Portal</h2><p>Scan a QR code from the desktop app to open an order.</p><form method="POST" action="/logout" style="margin-top:20px">'.csrf_field().'<button>Logout</button></form></div>');
    });
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    Route::get('/qr/{systemId}', [QrController::class, 'show'])->name('qr.show');
    Route::post('/qr/{systemId}/status', [QrController::class, 'updateStatus'])->name('qr.update-status');
    Route::get('/qr/{systemId}/label-image', [QrController::class, 'labelImage'])->name('qr.label-image');
    Route::get('/qr/{systemId}/print', [QrController::class, 'printPage'])->name('qr.print');

    // Gangsheet Label scan page — scanned barcode "GSL{id}" lands here.
    Route::get('/gs/{code}', [GangsheetLabelWebController::class, 'show'])->name('gs.show');
    Route::post('/gs/{code}/complete-all', [GangsheetLabelWebController::class, 'completeAll'])->name('gs.complete-all');
});
