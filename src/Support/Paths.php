<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Support;

use InvalidArgumentException;
use Symfony\Component\Filesystem\Path;

final class Paths
{
    public static function resolve(string $path, string $base): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]+:/', $path) === 1) {
            throw new InvalidArgumentException('A local filesystem path is required.');
        }

        return Path::canonicalize(Path::isAbsolute($path) ? $path : Path::makeAbsolute($path, $base));
    }

    /** Resolve existing ancestors, including symlinks, without following the final target link. */
    public static function destination(string $path): string
    {
        $remaining = [basename($path)];
        $ancestor = dirname($path);
        while (($physical = realpath($ancestor)) === false) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return Path::canonicalize($path);
            }
            $remaining[] = basename($ancestor);
            $ancestor = $parent;
        }

        return Path::join($physical, ...array_reverse($remaining));
    }

    public static function same(string $left, string $right): bool
    {
        return self::key($left) === self::key($right);
    }

    public static function contains(string $parent, string $child): bool
    {
        $parent = self::key($parent);
        $child = self::key($child);

        return $parent === $child || str_starts_with($child, $parent.'/');
    }

    public static function assertStorageTarget(string $source, string $target): void
    {
        $physicalSource = realpath($source);
        if ($physicalSource === false) {
            throw new InvalidArgumentException('Storage source directory does not exist.');
        }
        $destination = self::destination($target);
        if (self::contains($physicalSource, $destination) || self::contains($destination, $physicalSource)) {
            throw new InvalidArgumentException('Storage source and target must not overlap.');
        }
        foreach ([Path::getRoot($target), base_path(), public_path(), storage_path()] as $root) {
            if (self::same($destination, self::destination($root))) {
                throw new InvalidArgumentException('A filesystem or application root cannot be a storage target.');
            }
        }
    }

    private static function key(string $path): string
    {
        $normalized = rtrim(Path::canonicalize($path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
