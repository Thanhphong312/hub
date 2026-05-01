<?php

use App\Http\Controllers\Web\QrController;
use App\Http\Controllers\Web\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect('/home')
        : redirect()->route('login');
});

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
});
