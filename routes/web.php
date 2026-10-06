<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockRequestController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| Complete inventory — see PORTING-CONTRACT.md §3. Every action in
| src/lib/actions.ts maps to exactly one of these endpoints.
*/

// /signup never existed in this product: accounts are created by the owner.
Route::redirect('/signup', '/login')->name('signup');

// Owner bootstrap + login. EnsureSetupState sends visitors to /setup while no
// owner exists, kills /setup afterwards, and bounces signed-in users off the
// auth pages (port of src/proxy.ts).
Route::middleware('setup.state')->group(function (): void {
    Route::get('/setup', [AuthController::class, 'setupForm'])->name('setup');
    Route::post('/setup', [AuthController::class, 'setup']);

    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'active'])->group(function (): void {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Shop floor: stock table, adjustments, daily close, physical counts
    Route::get('/shops/{shop}', [ShopController::class, 'show'])->name('shop.show');
    Route::post('/shops/{shop}/models', [StockController::class, 'create'])->name('models.store');
    Route::post('/shops/{shop}/models/{model}', [StockController::class, 'update'])->name('models.update');
    Route::post('/shops/{shop}/models/{model}/adjust', [StockController::class, 'adjust'])->name('models.adjust');
    Route::post('/shops/{shop}/models/bulk', [StockController::class, 'bulkAdjust'])->name('models.bulk');
    Route::post('/shops/{shop}/close', [ShopController::class, 'submitClose'])->name('close.submit');
    Route::post('/shops/{shop}/close/{close}/lock', [ShopController::class, 'lockClose'])->name('close.lock');
    Route::post('/shops/{shop}/counts', [ShopController::class, 'submitCount'])->name('counts.submit');

    // Point of sale
    Route::get('/transactions/new', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::post('/transactions/{transaction}/review', [TransactionController::class, 'review'])->name('transactions.review');
    Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void'])->name('transactions.void');
    Route::post('/swapped-phones/{phone}/status', [TransactionController::class, 'swappedStatus'])->name('swapped.update');

    // Device catalogue (owner)
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('/devices/models/bulk', [DeviceController::class, 'bulkCreate'])->name('devices.bulk');

    // Stock requests
    Route::post('/requests/approve-all', [StockRequestController::class, 'approveAll'])->name('requests.approve-all');
    Route::post('/requests/{stockRequest}/approve', [StockRequestController::class, 'approve'])->name('requests.approve');
    Route::post('/requests/{stockRequest}/reject', [StockRequestController::class, 'reject'])->name('requests.reject');

    // Reports + reconciliation
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/reports/counts/{count}/approve', [ReportController::class, 'approveCount'])->name('counts.approve');
    Route::post('/reports/counts/{count}/apply', [ReportController::class, 'applyCount'])->name('counts.apply');

    // Settings (owner only — enforced in the controller, like the RLS policies)
    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::post('/shops', [SettingsController::class, 'createShop'])->name('shops.store');
        Route::post('/shops/{shop}/delete', [SettingsController::class, 'deleteShop'])->name('shops.delete');
        Route::post('/staff', [SettingsController::class, 'createStaff'])->name('staff.store');
        Route::post('/staff/{user}/deactivate', [SettingsController::class, 'deactivate'])->name('staff.deactivate');
        Route::post('/staff/{user}/reactivate', [SettingsController::class, 'reactivate'])->name('staff.reactivate');
        Route::post('/staff/{user}/reset-password', [SettingsController::class, 'resetPassword'])->name('staff.reset-password');
        Route::post('/models/bulk', [SettingsController::class, 'bulkCreateModels'])->name('models.bulk');
        Route::get('/backup/download', [SettingsController::class, 'downloadBackup'])->name('backup.download');
        Route::post('/backup/restore', [SettingsController::class, 'restoreBackup'])->name('backup.restore');
    });

    // Audit trails (owner)
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');

    // Account
    Route::get('/account', [AccountController::class, 'index'])->name('account.index');
    Route::post('/account/password', [AccountController::class, 'changePassword'])->name('account.password');
});
