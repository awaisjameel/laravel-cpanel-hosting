<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Http\Controllers;

use Awaisjameel\LaravelCpanelHosting\Actions\StorageLinkAction;
use Awaisjameel\LaravelCpanelHosting\Actions\SyncEnvAction;
use Awaisjameel\LaravelCpanelHosting\Events\DeployCompleted;
use Awaisjameel\LaravelCpanelHosting\Events\DeployStarting;
use Awaisjameel\LaravelCpanelHosting\Events\DeployStepCompleted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeployController extends Controller
{
    public function __construct(
        private readonly SyncEnvAction $syncEnvAction,
        private readonly StorageLinkAction $storageLinkAction,
    ) {}

    public function deploy(Request $request): JsonResponse
    {
        /** @var array<int, mixed> $steps */
        $steps = config('cpanel-hosting.pipeline.default_steps', []);
        if ($steps === []) {
            $steps = ['sync-env', 'maintenance-down', 'optimize-clear', 'migrate', 'cache', 'storage-link', 'maintenance-up'];
        }

        event(new DeployStarting($steps, $request->ip()));

        $stepResults = [];
        $stopOnFailure = (bool) config('cpanel-hosting.pipeline.stop_on_failure', true);
        $success = true;

        foreach ($steps as $step) {
            $name = $this->extractStepName($step);
            $result = $this->executePipelineStep($step);
            $stepResults[] = ['step' => $name, 'result' => $result];

            event(new DeployStepCompleted($name, $result));
            $this->logStepResult($name, $result);

            if (! $result['success']) {
                $success = false;
                if ($stopOnFailure) {
                    break;
                }
            }
        }

        event(new DeployCompleted($success, $stepResults));

        if (! $success) {
            return $this->response(
                false,
                'Deployment pipeline failed.',
                ['steps' => $stepResults],
                ['One or more deploy steps failed.'],
                500
            );
        }

        return $this->response(
            true,
            'Deployment pipeline completed.',
            ['steps' => $stepResults]
        );
    }

    public function syncEnv(): JsonResponse
    {
        return $this->responseFromStepResult($this->syncEnvAction->execute(), 'sync-env');
    }

    public function clear(): JsonResponse
    {
        return $this->responseFromStepResult($this->runArtisanCommand('optimize:clear'), 'clear');
    }

    public function migrate(): JsonResponse
    {
        return $this->responseFromStepResult($this->runArtisanCommand('migrate', ['--force' => true]), 'migrate');
    }

    public function migrateFresh(): JsonResponse
    {
        return $this->responseFromStepResult($this->runArtisanCommand('migrate:fresh', ['--force' => true]), 'migrate-fresh');
    }

    public function cache(): JsonResponse
    {
        return $this->responseFromStepResult($this->runCacheCommands(), 'cache');
    }

    public function queueRestart(): JsonResponse
    {
        return $this->responseFromStepResult($this->runArtisanCommand('queue:restart'), 'queue-restart');
    }

    public function storageLink(): JsonResponse
    {
        return $this->responseFromStepResult($this->storageLinkAction->execute(), 'storage-link');
    }

    public function maintenanceDown(): JsonResponse
    {
        return $this->responseFromStepResult($this->maintenanceDownStep(), 'maintenance-down');
    }

    public function maintenanceUp(): JsonResponse
    {
        return $this->responseFromStepResult($this->runArtisanCommand('up'), 'maintenance-up');
    }

    public function optimize(): JsonResponse
    {
        return $this->responseFromStepResult($this->runArtisanCommand('optimize'), 'optimize');
    }

    public function health(): JsonResponse
    {
        return $this->response(true, 'OK', [
            'app_env' => (string) config('app.env'),
            'timestamp' => now()->toIso8601String(),
            'route_prefix' => (string) config('cpanel-hosting.route_prefix', 'deploy'),
        ]);
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function executePipelineStep(mixed $step): array
    {
        if (is_callable($step)) {
            $result = $step();

            return $this->normalizeStepResult('closure', $result);
        }

        if (is_array($step) && isset($step['command']) && is_string($step['command'])) {
            $command = $step['command'];
            $parameters = isset($step['parameters']) && is_array($step['parameters']) ? $step['parameters'] : [];

            return $this->runArtisanCommand($command, $parameters);
        }

        if (! is_string($step)) {
            return $this->failedStep('invalid-step', 'Pipeline step must be a string, callable, or command array.');
        }

        if (str_starts_with($step, 'artisan:')) {
            return $this->runArtisanCommand(substr($step, 8));
        }

        return match ($step) {
            'sync-env' => $this->syncEnvAction->execute(),
            'maintenance-down' => $this->maintenanceDownStep(),
            'optimize-clear' => $this->runArtisanCommand('optimize:clear'),
            'migrate' => $this->runArtisanCommand('migrate', ['--force' => true]),
            'migrate-fresh' => $this->runArtisanCommand('migrate:fresh', ['--force' => true]),
            'cache' => $this->runCacheCommands(),
            'queue-restart' => $this->runArtisanCommand('queue:restart'),
            'storage-link' => $this->storageLinkAction->execute(),
            'maintenance-up' => $this->runArtisanCommand('up'),
            'optimize' => $this->runArtisanCommand('optimize'),
            default => $this->failedStep($step, "Unknown deploy step: {$step}"),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function responseFromStepResult(array $result, string $step): JsonResponse
    {
        if (! ($result['success'] ?? false)) {
            return $this->response(
                false,
                (string) ($result['message'] ?? 'Step failed.'),
                ['step' => $step, 'result' => $result['data'] ?? []],
                $this->extractErrors($result),
                500
            );
        }

        return $this->response(
            true,
            (string) ($result['message'] ?? 'Step completed.'),
            ['step' => $step, 'result' => $result['data'] ?? []],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractErrors(array $result): array
    {
        $errors = $result['errors'] ?? [];

        return is_array($errors) ? array_values(array_map('strval', $errors)) : ['Unknown error'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string[]  $errors
     */
    private function response(bool $success, string $message, array $data = [], array $errors = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function runArtisanCommand(string $command, array $parameters = []): array
    {
        try {
            $exitCode = Artisan::call($command, $parameters);
            $output = trim((string) Artisan::output());
            $success = $exitCode === 0;

            return [
                'success' => $success,
                'message' => $success ? "Artisan command [{$command}] executed." : "Artisan command [{$command}] failed.",
                'data' => [
                    'command' => $command,
                    'parameters' => $parameters,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ],
                'errors' => $success ? [] : ["Artisan command [{$command}] exited with code {$exitCode}."],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->failedStep($command, 'Artisan command execution threw an exception.');
        }
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function maintenanceDownStep(): array
    {
        $parameters = ['--retry' => 60];
        $secret = (string) config('cpanel-hosting.maintenance.secret', '');
        if ($secret !== '') {
            $parameters['--secret'] = $secret;
        }

        return $this->runArtisanCommand('down', $parameters);
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function runCacheCommands(): array
    {
        $commands = ['config:cache', 'route:cache', 'view:cache', 'event:cache'];

        $results = [];
        $errors = [];
        $success = true;

        foreach ($commands as $command) {
            $result = $this->runArtisanCommand($command);
            $results[$command] = $result['data'];

            if (! $result['success']) {
                $success = false;
                $errors = array_merge($errors, $this->extractErrors($result));
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'Cache commands completed.' : 'One or more cache commands failed.',
            'data' => ['commands' => $results],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function normalizeStepResult(string $name, mixed $result): array
    {
        if (is_array($result) && array_key_exists('success', $result)) {
            $success = (bool) ($result['success'] ?? false);
            $message = (string) ($result['message'] ?? ($success ? 'Step completed.' : 'Step failed.'));
            $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
            $errors = isset($result['errors']) && is_array($result['errors']) ? array_values(array_map('strval', $result['errors'])) : [];

            return [
                'success' => $success,
                'message' => $message,
                'data' => $data,
                'errors' => $errors,
            ];
        }

        if (is_bool($result)) {
            return [
                'success' => $result,
                'message' => $result ? 'Step completed.' : 'Step failed.',
                'data' => [],
                'errors' => $result ? [] : ['Step failed.'],
            ];
        }

        return $this->failedStep($name, 'Callable pipeline step must return bool or structured array.');
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function failedStep(string $step, string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => ['step' => $step],
            'errors' => [$message],
        ];
    }

    private function extractStepName(mixed $step): string
    {
        if (is_string($step)) {
            return $step;
        }

        if (is_array($step) && isset($step['command']) && is_string($step['command'])) {
            return $step['command'];
        }

        return 'closure';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function logStepResult(string $step, array $result): void
    {
        $channel = (string) config('cpanel-hosting.logging.channel', 'deploy');

        try {
            if (($result['success'] ?? false) === true) {
                Log::channel($channel)->info("Deploy step [{$step}] completed.", $result['data'] ?? []);

                return;
            }

            Log::channel($channel)->error("Deploy step [{$step}] failed.", [
                'data' => $result['data'] ?? [],
                'errors' => $result['errors'] ?? [],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            Log::error("Deploy step [{$step}] logging failed.");
        }
    }
}
