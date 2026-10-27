<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Support;

use InvalidArgumentException;

final class Settings
{
    public static function text(string $key, string $default = ''): string
    {
        $value = config('cpanel-hosting.'.$key, $default);
        if ($value === null) {
            return $default;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("cpanel-hosting.{$key} must be a string.");
        }

        return $value;
    }

    public static function flag(string $key, bool $default): bool
    {
        $value = config('cpanel-hosting.'.$key, $default);
        $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            throw new InvalidArgumentException("cpanel-hosting.{$key} must be a boolean.");
        }

        return $flag;
    }

    /** @return positive-int */
    public static function positiveInteger(string $key, int $default): int
    {
        $value = config('cpanel-hosting.'.$key, $default);
        $parsed = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($parsed === false || $parsed < 1) {
            throw new InvalidArgumentException("cpanel-hosting.{$key} must be a positive integer.");
        }

        return $parsed;
    }
}
