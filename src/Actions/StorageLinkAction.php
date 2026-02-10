<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Actions;

use Illuminate\Support\Facades\File;
use Throwable;

final class StorageLinkAction
{
    /**
     * @return array{success: bool, message: string, data: array<string, mixed>, errors: string[]}
     */
    public function execute(): array
    {
        $source = $this->resolveSourcePath();
        $target = $this->resolvePublicPath();

        if (! File::isDirectory($source)) {
            return $this->failure("Storage source directory does not exist: {$source}");
        }

        $preferSymlink = (bool) config('cpanel-hosting.storage_link.prefer_symlink', true);
        $fallbackCopy = (bool) config('cpanel-hosting.storage_link.fallback_copy', true);

        $symlinkError = null;

        if ($preferSymlink && $this->createSymlink($source, $target, $symlinkError)) {
            return [
                'success' => true,
                'message' => 'Storage linked using symlink.',
                'data' => [
                    'mode' => 'symlink',
                    'source' => $source,
                    'target' => $target,
                ],
                'errors' => [],
            ];
        }

        if (! $fallbackCopy) {
            return $this->failure(
                'Symlink creation failed and copy fallback is disabled.',
                ['source' => $source, 'target' => $target],
                $symlinkError !== null ? [$symlinkError] : []
            );
        }

        try {
            $this->removeExistingTarget($target);
            File::ensureDirectoryExists($target);

            if (! File::copyDirectory($source, $target)) {
                return $this->failure('Failed to copy storage directory to public path.', [
                    'source' => $source,
                    'target' => $target,
                ]);
            }

            $this->applyPermissions($target);

            $warnings = [];
            if ($symlinkError !== null) {
                $warnings[] = $symlinkError;
            }

            return [
                'success' => true,
                'message' => 'Storage mirrored by directory copy fallback.',
                'data' => [
                    'mode' => 'copy',
                    'source' => $source,
                    'target' => $target,
                ],
                'errors' => $warnings,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('Storage link action failed.', [
                'source' => $source,
                'target' => $target,
            ]);
        }
    }

    private function resolveSourcePath(): string
    {
        $configured = (string) config('cpanel-hosting.storage_link.source', 'app/public');

        if ($this->isAbsolutePath($configured)) {
            return $configured;
        }

        return storage_path($configured);
    }

    private function resolvePublicPath(): string
    {
        $configured = (string) config('cpanel-hosting.storage_link.public_path', 'storage');

        if ($this->isAbsolutePath($configured)) {
            return $configured;
        }

        return public_path($configured);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1;
    }

    private function createSymlink(string $source, string $target, ?string &$error): bool
    {
        if (! function_exists('symlink')) {
            $error = 'symlink() is not available on this host.';

            return false;
        }

        try {
            if (is_link($target)) {
                $existingTarget = readlink($target);
                if ($existingTarget !== false && realpath($existingTarget) === realpath($source)) {
                    return true;
                }

                @unlink($target);
            } elseif (File::exists($target)) {
                $this->removeExistingTarget($target);
            }

            set_error_handler(static function (int $severity, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });

            $created = symlink($source, $target);
            restore_error_handler();

            if (! $created) {
                $error ??= 'symlink() returned false.';
            }

            return $created;
        } catch (Throwable $exception) {
            restore_error_handler();
            $error = $exception->getMessage();

            return false;
        }
    }

    private function removeExistingTarget(string $target): void
    {
        if (is_link($target) || File::isFile($target)) {
            File::delete($target);

            return;
        }

        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }
    }

    private function applyPermissions(string $target): void
    {
        foreach (File::allDirectories($target) as $directory) {
            @chmod($directory, 0755);
        }

        foreach (File::allFiles($target) as $file) {
            @chmod($file->getPathname(), 0644);
        }
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
