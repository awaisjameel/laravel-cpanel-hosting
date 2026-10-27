<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Actions;

use Awaisjameel\LaravelCpanelHosting\Support\AtomicFiles;
use Awaisjameel\LaravelCpanelHosting\Support\Paths;
use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Awaisjameel\LaravelCpanelHosting\Support\StepResult;
use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** @phpstan-import-type Result from StepResult */
final class SyncEnvAction
{
    /** @return Result */
    public function execute(): array
    {
        try {
            $source = Paths::resolve(Settings::text('sync_env.source', '.env.server'), base_path());
            $target = Paths::resolve(Settings::text('sync_env.target', '.env'), base_path());
            if (! File::isFile($source)) {
                return StepResult::make(false, 'Source environment file does not exist.');
            }
            if (is_link($target) || Paths::same(realpath($source) ?: $source, Paths::destination($target))) {
                return StepResult::make(false, 'Environment source and target must be distinct regular files.');
            }
            // Parse and validate the incoming file before touching the active file.
            $content = File::get($source);
            $values = Dotenv::parse($content);
            $required = config('cpanel-hosting.sync_env.required_keys', []);
            if (! is_array($required)) {
                throw new RuntimeException('sync_env.required_keys must be an array of key names.');
            }
            $missing = [];
            foreach ($required as $key) {
                if (! is_string($key) || trim($key) === '') {
                    throw new RuntimeException('Required environment key names must be non-empty strings.');
                }
                if (! array_key_exists($key, $values)) {
                    $missing[] = $key;
                }
            }
            if ($missing !== []) {
                return StepResult::make(false, 'Required environment keys are missing from the source.', ['missing_keys' => $missing]);
            }
            if (File::exists($target) && (! File::isFile($target) || ! is_writable($target))) {
                return StepResult::make(false, 'Target environment file is not a writable regular file.');
            }
            $backup = Settings::flag('sync_env.backup', true) && File::isFile($target)
                ? AtomicFiles::backup($target)
                : null;
            AtomicFiles::write($target, $content);

            return StepResult::make(true, 'Environment file synchronized.', ['source' => $source, 'target' => $target, 'backup' => $backup]);
        } catch (InvalidFileException) {
            return StepResult::make(false, 'Source environment file syntax is invalid; the active file was preserved.');
        } catch (Throwable $exception) {
            report($exception);

            return StepResult::make(false, 'Failed to synchronize the environment file; the active file was preserved.');
        }
    }
}
