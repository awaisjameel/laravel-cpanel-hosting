<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Actions;

use Illuminate\Support\Facades\File;
use Throwable;

final class SyncEnvAction
{
    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    public function execute(): array
    {
        $source = $this->resolvePath((string) config('cpanel-hosting.sync_env.source', '.env.server'));
        $target = $this->resolvePath((string) config('cpanel-hosting.sync_env.target', '.env'));
        $backupEnabled = (bool) config('cpanel-hosting.sync_env.backup', true);

        try {
            if (! File::exists($source)) {
                return $this->failure("Source env file not found: {$source}");
            }

            $targetDirectory = dirname($target);
            if (! File::isDirectory($targetDirectory)) {
                File::ensureDirectoryExists($targetDirectory);
            }

            if (File::exists($target) && ! is_writable($target)) {
                return $this->failure("Target env file is not writable: {$target}");
            }

            if (! File::exists($target) && ! is_writable($targetDirectory)) {
                return $this->failure("Target directory is not writable: {$targetDirectory}");
            }

            $backupPath = null;
            if ($backupEnabled && File::exists($target)) {
                $backupPath = $target.'.backup.'.date('YmdHis');
                File::copy($target, $backupPath);
            }

            File::copy($source, $target);

            $missingKeys = $this->validateRequiredKeys($target);
            if ($missingKeys !== []) {
                return $this->failure(
                    'Required env keys are missing after sync.',
                    [
                        'source' => $source,
                        'target' => $target,
                        'backup' => $backupPath,
                        'missing_keys' => $missingKeys,
                    ],
                    ['Missing keys: '.implode(', ', $missingKeys)]
                );
            }

            return [
                'success' => true,
                'message' => 'Environment file synchronized.',
                'data' => [
                    'source' => $source,
                    'target' => $target,
                    'backup' => $backupPath,
                ],
                'errors' => [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('Failed to synchronize environment file.', [
                'source' => $source,
                'target' => $target,
            ]);
        }
    }

    /**
     * @return string[]
     */
    private function validateRequiredKeys(string $target): array
    {
        $requiredKeys = config('cpanel-hosting.sync_env.required_keys', []);
        if (! is_array($requiredKeys) || $requiredKeys === []) {
            return [];
        }

        $content = File::get($target);
        $availableKeys = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            $key = trim(strtok($trimmed, '=') ?: '');
            if ($key !== '') {
                $availableKeys[] = $key;
            }
        }

        $missingKeys = [];

        foreach ($requiredKeys as $requiredKey) {
            $requiredKey = is_string($requiredKey) ? trim($requiredKey) : '';
            if ($requiredKey === '') {
                continue;
            }

            if (! in_array($requiredKey, $availableKeys, true)) {
                $missingKeys[] = $requiredKey;
            }
        }

        return $missingKeys;
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string[]  $errors
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    private function failure(string $message, array $data = [], array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => $data,
            'errors' => $errors === [] ? [$message] : $errors,
        ];
    }
}
