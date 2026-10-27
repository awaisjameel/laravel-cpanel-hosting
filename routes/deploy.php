<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Http\Controllers\DeployController;
use Awaisjameel\LaravelCpanelHosting\Http\Middleware\EnsureDeployTokenIsValid;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::prefix((string) config('cpanel-hosting.route_prefix', 'deploy'))
    ->middleware([EnsureDeployTokenIsValid::class])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        'throttle',
    ])
    ->group(function (): void {
        Route::post('/', [DeployController::class, 'deploy']);
        Route::post('sync-env', [DeployController::class, 'syncEnv']);
        Route::post('clear', [DeployController::class, 'clear']);
        Route::post('migrate', [DeployController::class, 'migrate']);
        Route::post('migrate-fresh', [DeployController::class, 'migrateFresh']);
        Route::post('cache', [DeployController::class, 'cache']);
        Route::post('queue-restart', [DeployController::class, 'queueRestart']);
        Route::post('storage-link', [DeployController::class, 'storageLink']);
        Route::post('maintenance-down', [DeployController::class, 'maintenanceDown']);
        Route::post('maintenance-up', [DeployController::class, 'maintenanceUp']);
        Route::post('optimize', [DeployController::class, 'optimize']);
        Route::get('health', [DeployController::class, 'health']);
    });
