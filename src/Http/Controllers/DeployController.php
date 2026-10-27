<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Http\Controllers;

use Awaisjameel\LaravelCpanelHosting\Actions\DeployAction;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Awaisjameel\LaravelCpanelHosting\Support\StepResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** @phpstan-import-type Result from StepResult */
final class DeployController extends Controller
{
    public function __construct(private readonly DeployAction $deployAction) {}

    public function deploy(Request $request): JsonResponse
    {
        return $this->response($this->deployAction->execute($request->ip()));
    }

    public function syncEnv(): JsonResponse
    {
        return $this->step('sync-env');
    }

    public function clear(): JsonResponse
    {
        return $this->step('clear');
    }

    public function migrate(): JsonResponse
    {
        return $this->step('migrate');
    }

    public function migrateFresh(): JsonResponse
    {
        return $this->step('migrate-fresh');
    }

    public function cache(): JsonResponse
    {
        return $this->step('cache');
    }

    public function queueRestart(): JsonResponse
    {
        return $this->step('queue-restart');
    }

    public function storageLink(): JsonResponse
    {
        return $this->step('storage-link');
    }

    public function maintenanceDown(): JsonResponse
    {
        return $this->step('maintenance-down');
    }

    public function maintenanceUp(): JsonResponse
    {
        return $this->step('maintenance-up');
    }

    public function optimize(): JsonResponse
    {
        return $this->step('optimize');
    }

    public function health(): JsonResponse
    {
        return $this->response(StepResult::make(true, 'OK', [
            'app_env' => config('app.env'),
            'timestamp' => now()->toIso8601String(),
            'route_prefix' => Settings::text('route_prefix', 'deploy'),
        ]));
    }

    private function step(string $step): JsonResponse
    {
        $result = $this->deployAction->executeStep($step);

        return $this->response(StepResult::make($result['success'], $result['message'], ['step' => $step, 'result' => $result['data']], $result['errors']));
    }

    /** @param Result $result */
    private function response(array $result): JsonResponse
    {
        return response()->json($result, $result['success'] ? 200 : 500);
    }
}
