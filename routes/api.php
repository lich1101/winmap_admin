<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MonitoredWebsiteController;
use App\Http\Controllers\Api\TerminalController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('dashboard', DashboardController::class);

        Route::get('websites/discovery', [MonitoredWebsiteController::class, 'discovery']);
        Route::post('websites/discovery/sync', [MonitoredWebsiteController::class, 'syncDiscovery']);
        Route::apiResource('websites', MonitoredWebsiteController::class);
        Route::post('websites/{website}/refresh', [MonitoredWebsiteController::class, 'refresh']);

        Route::post('terminal/run', [TerminalController::class, 'run']);
        Route::get('terminal/history', [TerminalController::class, 'history']);
    });
});
