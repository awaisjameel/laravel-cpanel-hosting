<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Support;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class EnvironmentFile
{
    /** @param array<string, string> $values */
    public static function update(string $file, array $values): void
    {
        $content = File::isFile($file) ? File::get($file) : '';
        // Validate before any backup or replacement, including multiline existing values.
        try {
            $existing = Dotenv::parse($content);
        } catch (InvalidFileException) {
            throw new InvalidArgumentException('The existing environment file has invalid syntax.');
        }
        foreach ($values as $key => $value) {
            if (preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('Environment keys or values contain invalid characters.');
            }
            // phpdotenv supports these escapes and treats escaped dollars literally.
            $encoded = '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';
            $line = $key.'='.$encoded;
            // Replacing multiline definitions with a line regex could corrupt the file.
            if (array_key_exists($key, $existing) && str_contains($existing[$key] ?? '', "\n")) {
                throw new InvalidArgumentException('Replace multiline deploy values manually before running the installer.');
            }
            $pattern = '/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=.*$/m';
            if (preg_match($pattern, $content) === 1) {
                $content = preg_replace_callback($pattern, static fn (): string => $line, $content) ?? throw new InvalidArgumentException('Unable to update environment value.');
            } else {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }
        Dotenv::parse($content);
        if (File::isFile($file)) {
            AtomicFiles::backup($file);
        }
        AtomicFiles::write($file, $content);
    }
}
