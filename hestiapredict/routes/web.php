<?php

use App\Http\Controllers\DashboardAuthController;
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::redirect('/', '/dashboard/login');

Route::get('/dashboard/login', [DashboardAuthController::class, 'showLogin'])
    ->name('dashboard.login');
Route::post('/dashboard/login', [DashboardAuthController::class, 'login'])
    ->name('dashboard.login.submit');

Route::middleware(['auth', 'dashboard.admin'])->group(function () {
    Route::get('/dashboard', function () {
        $loginHistories = Schema::hasTable('login_histories')
            ? LoginHistory::query()
                ->whereIn('role', ['admin', 'superadmin', 'receptionist'])
                ->latest('logged_in_at')
                ->limit(100)
                ->get()
            : collect();

        return view('dashboard', compact('loginHistories'));
    })->name('dashboard');
    Route::post('/dashboard/logout', [DashboardAuthController::class, 'logout'])
        ->name('dashboard.logout');
});
