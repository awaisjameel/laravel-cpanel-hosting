<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDeployTokenIsValid
{
    /**
     * @var array<string, int[]>
     */
    private static array $attempts = [];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cpanel-hosting.enabled', false)) {
            abort(404);
        }

        if (! $this->isIpAllowed($request)) {
            abort(403, 'Forbidden');
        }

        if (! $this->passesRateLimit($request)) {
            abort(429, 'Too many requests');
        }

        if ($this->hasValidWebhookSignature($request) || $this->hasValidToken($request)) {
            return $next($request);
        }

        abort(403, 'Forbidden');
    }

    private function isIpAllowed(Request $request): bool
    {
        $allowedIps = config('cpanel-hosting.allowed_ips');

        if (is_string($allowedIps)) {
            $allowedIps = array_filter(array_map('trim', explode(',', $allowedIps)));
        }

        if (! is_array($allowedIps) || $allowedIps === []) {
            return true;
        }

        $requestIp = (string) $request->ip();

        return in_array($requestIp, $allowedIps, true);
    }

    private function hasValidToken(Request $request): bool
    {
        $expectedToken = (string) config('cpanel-hosting.token', '');
        if ($expectedToken === '') {
            return false;
        }

        $provided = $request->header('X-Deploy-Token');
        if (! is_string($provided) || $provided === '') {
            $provided = (string) $request->query('token', '');
        }

        if ($provided === '') {
            return false;
        }

        return hash_equals($expectedToken, $provided);
    }

    private function hasValidWebhookSignature(Request $request): bool
    {
        $secret = (string) config('cpanel-hosting.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $githubSignature = $request->header('X-Hub-Signature-256');
        if (is_string($githubSignature) && str_starts_with($githubSignature, 'sha256=')) {
            $provided = substr($githubSignature, 7);
            $computed = hash_hmac('sha256', $request->getContent(), $secret);

            return hash_equals($computed, $provided);
        }

        $gitlabToken = $request->header('X-Gitlab-Token');
        if (is_string($gitlabToken) && $gitlabToken !== '') {
            return hash_equals($secret, $gitlabToken);
        }

        return false;
    }

    private function passesRateLimit(Request $request): bool
    {
        if (! (bool) config('cpanel-hosting.rate_limit.enabled', false)) {
            return true;
        }

        $maxAttempts = max(1, (int) config('cpanel-hosting.rate_limit.max_attempts', 30));
        $decaySeconds = max(1, (int) config('cpanel-hosting.rate_limit.decay_seconds', 60));
        $key = (string) ($request->ip() ?? 'unknown');
        $now = time();

        $attempts = self::$attempts[$key] ?? [];
        $attempts = array_values(array_filter(
            $attempts,
            static fn (int $timestamp): bool => $timestamp >= ($now - $decaySeconds)
        ));

        if (count($attempts) >= $maxAttempts) {
            self::$attempts[$key] = $attempts;

            return false;
        }

        $attempts[] = $now;
        self::$attempts[$key] = $attempts;

        return true;
    }
}
