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
        Route::get('/', [DeployController::class, 'deploy']);
        Route::get('sync-env', [DeployController::class, 'syncEnv']);
        Route::get('clear', [DeployController::class, 'clear']);
        Route::get('migrate', [DeployController::class, 'migrate']);
        Route::get('migrate-fresh', [DeployController::class, 'migrateFresh']);
        Route::get('cache', [DeployController::class, 'cache']);
        Route::get('queue-restart', [DeployController::class, 'queueRestart']);
        Route::get('storage-link', [DeployController::class, 'storageLink']);
        Route::get('maintenance-down', [DeployController::class, 'maintenanceDown']);
        Route::get('maintenance-up', [DeployController::class, 'maintenanceUp']);
        Route::get('optimize', [DeployController::class, 'optimize']);
        Route::get('health', [DeployController::class, 'health']);
    });
