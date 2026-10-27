<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Support;

/** @phpstan-type Result array{success: bool, message: string, data: array<array-key, mixed>, errors: list<string>} */
final class StepResult
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $errors
     * @return Result
     */
    public static function make(bool $success, string $message, array $data = [], array $errors = []): array
    {
        return ['success' => $success, 'message' => $message, 'data' => $data, 'errors' => $errors === [] && ! $success ? [$message] : $errors];
    }
}
