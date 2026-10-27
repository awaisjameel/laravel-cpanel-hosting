<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Actions;

use Awaisjameel\LaravelCpanelHosting\Support\Paths;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Awaisjameel\LaravelCpanelHosting\Support\StepResult;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

/** @phpstan-import-type Result from StepResult */
final class StorageLinkAction
{
    /** @return Result */
    public function execute(): array
    {
        $filesystem = new Filesystem;
        $candidate = null;
        try {
            $source = Paths::resolve(Settings::text('storage_link.source', 'app/public'), storage_path());
            $target = Paths::resolve(Settings::text('storage_link.public_path', 'storage'), public_path());
            Paths::assertStorageTarget($source, $target);
            $filesystem->mkdir(dirname($target));
            $candidate = dirname($target).'/.cpanel-storage-'.bin2hex(random_bytes(12));
            $preferSymlink = Settings::flag('storage_link.prefer_symlink', true);
            $fallbackCopy = Settings::flag('storage_link.fallback_copy', true);
            $warnings = [];
            $mode = 'copy';
            if ($preferSymlink) {
                if (is_link($target) && realpath($target) === realpath($source)) {
                    return StepResult::make(true, 'Storage already linked.', ['mode' => 'symlink', 'source' => $source, 'target' => $target]);
                }
                try {
                    $filesystem->symlink($source, $candidate);
                    $mode = 'symlink';
                } catch (Throwable $exception) {
                    if (! $fallbackCopy) {
                        throw $exception;
                    }
                    $warnings[] = 'Symlink creation was unavailable; used directory copy.';
                }
            } elseif (! $fallbackCopy) {
                return StepResult::make(false, 'Both storage linking methods are disabled.');
            }
            if ($mode === 'copy') {
                // A new target keeps the previous public tree intact if copying fails.
                $filesystem->mirror($source, $candidate);
                $filesystem->chmod($candidate, 0755);
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $item) {
                    if (! $item->isLink()) {
                        $filesystem->chmod($item->getPathname(), $item->isDir() ? 0755 : 0644);
                    }
                }
            }
            $backup = null;
            if (file_exists($target) || is_link($target)) {
                $backup = dirname($target).'/.cpanel-old-'.bin2hex(random_bytes(12));
                $filesystem->rename($target, $backup);
            }
            try {
                $filesystem->rename($candidate, $target);
                $candidate = null;
            } catch (Throwable $exception) {
                if ($backup !== null) {
                    $filesystem->rename($backup, $target);
                }
                throw $exception;
            }
            if ($backup !== null) {
                try {
                    $filesystem->remove($backup);
                } catch (Throwable $exception) {
                    report($exception);
                    $warnings[] = 'Previous storage target backup could not be removed.';
                }
            }

            return StepResult::make(true, $mode === 'symlink' ? 'Storage linked using symlink.' : 'Storage mirrored by directory copy fallback.', ['mode' => $mode, 'source' => $source, 'target' => $target], $warnings);
        } catch (Throwable $exception) {
            report($exception);

            return StepResult::make(false, 'Storage link action failed. Check source/target paths, overlap and permissions.');
        } finally {
            if ($candidate !== null && (file_exists($candidate) || is_link($candidate))) {
                // Only this operation's random staging path is eligible for removal.
                try {
                    $filesystem->remove($candidate);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }
}
