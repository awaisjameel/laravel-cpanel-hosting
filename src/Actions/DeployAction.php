<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Actions;

use Awaisjameel\LaravelCpanelHosting\Contracts\DeploymentStep;
use Awaisjameel\LaravelCpanelHosting\Events\DeployCompleted;
use Awaisjameel\LaravelCpanelHosting\Events\DeployStarting;
use Awaisjameel\LaravelCpanelHosting\Events\DeployStepCompleted;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Awaisjameel\LaravelCpanelHosting\Support\StepResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/** @phpstan-import-type Result from StepResult */
final class DeployAction
{
    public function __construct(
        private readonly SyncEnvAction $syncEnvAction,
        private readonly StorageLinkAction $storageLinkAction,
    ) {}

    /** @return Result */
    public function execute(?string $ip = null): array
    {
        $steps = config('cpanel-hosting.pipeline.default_steps', []);
        if (! is_array($steps) || $steps === []) {
            return StepResult::make(false, 'Configure at least one deployment step.');
        }
        $steps = array_values($steps);
        $stopOnFailure = Settings::flag('pipeline.stop_on_failure', true);
        event(new DeployStarting($steps, $ip));
        $results = [];
        $success = true;
        foreach ($steps as $step) {
            $result = $this->executeStep($step);
            $results[] = ['step' => $this->stepName($step), 'result' => $result];
            if (! $result['success']) {
                $success = false;
                if ($stopOnFailure) {
                    break;
                }
            }
        }
        event(new DeployCompleted($success, $results));

        return StepResult::make($success, $success ? 'Deployment pipeline completed.' : 'Deployment pipeline failed.', ['steps' => $results]);
    }

    /** @return Result */
    public function executeStep(mixed $step): array
    {
        $name = $this->stepName($step);
        try {
            $result = $this->runStep($step);
        } catch (Throwable $exception) {
            report($exception);
            $result = StepResult::make(false, 'Deployment step threw an exception.');
        }
        event(new DeployStepCompleted($name, $result));
        // Never log arbitrary callback data, command output, or credentials.
        try {
            Log::channel(Settings::text('logging.channel', 'deploy'))->log($result['success'] ? 'info' : 'error', 'Deployment step finished.', [
                'step' => $name,
                'success' => $result['success'],
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    /** @return Result */
    private function runStep(mixed $step): array
    {
        if (! is_string($step) && is_callable($step)) {
            return $this->normalize($step());
        }
        if (is_array($step) && isset($step['command']) && is_string($step['command'])) {
            $parameters = $step['parameters'] ?? [];
            if (! is_array($parameters)) {
                return StepResult::make(false, 'Artisan parameters must be an array.');
            }

            return $this->artisan($step['command'], $parameters);
        }
        if (! is_string($step)) {
            return StepResult::make(false, 'Pipeline step must be a string, callable, or command array.');
        }
        if (str_starts_with($step, 'artisan:')) {
            return $this->artisan(substr($step, 8));
        }
        if (is_subclass_of($step, DeploymentStep::class)) {
            return $this->normalize(app($step)->execute());
        }

        return match ($step) {
            'sync-env' => $this->syncEnvAction->execute(),
            'storage-link' => $this->storageLinkAction->execute(),
            'clear', 'optimize-clear' => $this->artisan('optimize:clear'),
            'migrate', 'migrate-fresh' => $this->artisan($step === 'migrate' ? 'migrate' : 'migrate:fresh', ['--force' => true]),
            'maintenance-down' => $this->artisan('down', array_filter(['--retry' => 60, '--secret' => Settings::text('maintenance.secret')], static fn (mixed $value): bool => $value !== '')),
            'maintenance-up' => $this->artisan('up'),
            'queue-restart' => $this->artisan('queue:restart'),
            'optimize' => $this->artisan('optimize'),
            'cache' => $this->cache(),
            default => StepResult::make(false, "Unknown deploy step: {$step}"),
        };
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return Result
     */
    private function artisan(string $command, array $parameters = []): array
    {
        $exitCode = Artisan::call($command, $parameters);
        $output = trim(Artisan::output());
        $safeParameters = $parameters;
        foreach ($parameters as $key => $value) {
            if (is_string($key) && preg_match('/secret|password|token|api.?key/i', $key) === 1) {
                $safeParameters[$key] = '[REDACTED]';
                if (is_string($value) && $value !== '') {
                    $output = str_replace($value, '[REDACTED]', $output);
                }
            }
        }

        return StepResult::make($exitCode === 0, "Artisan command [{$command}] ".($exitCode === 0 ? 'executed.' : 'failed.'), [
            'command' => $command,
            'parameters' => $safeParameters,
            'exit_code' => $exitCode,
            'output' => $output,
        ]);
    }

    /** @return Result */
    private function cache(): array
    {
        $results = [];
        $errors = [];
        foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
            $result = $this->artisan($command);
            $results[$command] = $result['data'];
            $errors = array_merge($errors, $result['errors']);
        }

        return StepResult::make($errors === [], $errors === [] ? 'Cache commands completed.' : 'One or more cache commands failed.', ['commands' => $results], $errors);
    }

    /** @return Result */
    private function normalize(mixed $result): array
    {
        if (is_bool($result)) {
            return StepResult::make($result, $result ? 'Step completed.' : 'Step failed.');
        }
        if (is_array($result) && isset($result['errors']) && is_array($result['errors'])) {
            foreach ($result['errors'] as $error) {
                if (! is_string($error)) {
                    return StepResult::make(false, 'Callable result errors must be strings.');
                }
            }
        }
        if (is_array($result) && isset($result['success']) && is_bool($result['success'])
            && (! isset($result['message']) || is_string($result['message']))
            && (! isset($result['data']) || is_array($result['data']))
            && (! isset($result['errors']) || is_array($result['errors']))) {
            return StepResult::make($result['success'], $result['message'] ?? ($result['success'] ? 'Step completed.' : 'Step failed.'), $result['data'] ?? [], array_values($result['errors'] ?? []));
        }

        return StepResult::make(false, 'Callable pipeline step must return bool or a valid structured result.');
    }

    private function stepName(mixed $step): string
    {
        if (is_string($step)) {
            return $step;
        }
        if (is_array($step) && isset($step['command']) && is_string($step['command'])) {
            return $step['command'];
        }

        return 'closure';
    }
}
