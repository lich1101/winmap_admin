<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MonitoredWebsiteController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\TerminalController;
use App\Http\Controllers\Api\WebsiteProvisionController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('setup/status', [SetupController::class, 'status']);
        Route::post('setup/discover', [SetupController::class, 'discover']);
        Route::post('setup/complete', [SetupController::class, 'complete']);

        Route::middleware('setup.complete')->group(function (): void {
            Route::get('dashboard', DashboardController::class);

            Route::get('websites/discovery', [MonitoredWebsiteController::class, 'discovery']);
            Route::post('websites/discovery/sync', [MonitoredWebsiteController::class, 'syncDiscovery']);
            Route::apiResource('websites', MonitoredWebsiteController::class);
            Route::post('websites/{website}/refresh', [MonitoredWebsiteController::class, 'refresh']);

            Route::post('terminal/run', [TerminalController::class, 'run']);
            Route::get('terminal/history', [TerminalController::class, 'history']);

            Route::get('website-provision/runs', [WebsiteProvisionController::class, 'index']);
            Route::post('website-provision/runs', [WebsiteProvisionController::class, 'store']);
            Route::get('website-provision/runs/{run}', [WebsiteProvisionController::class, 'show']);
            Route::post('website-provision/runs/{run}/run-all', [WebsiteProvisionController::class, 'runAll']);
            Route::post('website-provision/runs/{run}/steps/{step}', [WebsiteProvisionController::class, 'runStep']);
        });
    });
});
