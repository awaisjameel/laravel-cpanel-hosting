<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtomicFiles
{
    public static function write(string $target, string $content, int $mode = 0600): void
    {
        if (is_link($target)) {
            throw new RuntimeException('Refusing to replace a symbolic-link file; configure its intended destination explicitly.');
        }
        File::ensureDirectoryExists(dirname($target));
        $temporary = dirname($target).'/.cpanel-'.bin2hex(random_bytes(12)).'.tmp';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Unable to create a staging file.');
        }
        try {
            if (! chmod($temporary, $mode)) {
                throw new RuntimeException('Unable to set staging file permissions.');
            }
            $offset = 0;
            $length = strlen($content);
            while ($offset < $length) {
                $written = fwrite($stream, substr($content, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to finish writing the staging file.');
                }
                $offset += $written;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Unable to flush the staging file.');
            }
            fclose($stream);
            if (! rename($temporary, $target)) {
                throw new RuntimeException('Unable to atomically replace the destination file.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function backup(string $target): string
    {
        $backup = $target.'.backup.'.date('YmdHis').'.'.bin2hex(random_bytes(6));
        self::write($backup, File::get($target));

        return $backup;
    }
}
