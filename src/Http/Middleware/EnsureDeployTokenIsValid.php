<?php

declare(strict_types=1);

namespace Awaisjameel\LaravelCpanelHosting\Http\Middleware;

use Awaisjameel\LaravelCpanelHosting\Support\Settings;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDeployTokenIsValid
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Settings::flag('enabled', false)) {
            abort(404);
        }

        if (! $this->isIpAllowed($request)) {
            abort(403, 'Forbidden');
        }

        $this->enforceRateLimit($request);

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

        if ($allowedIps === null || $allowedIps === []) {
            return true;
        }

        if (! is_array($allowedIps)) {
            return false;
        }

        $requestIp = (string) $request->ip();

        return in_array($requestIp, $allowedIps, true);
    }

    private function hasValidToken(Request $request): bool
    {
        $expectedToken = Settings::text('token');
        if ($expectedToken === '') {
            return false;
        }

        $provided = $request->header('X-Deploy-Token');
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expectedToken, $provided);
    }

    private function hasValidWebhookSignature(Request $request): bool
    {
        $secret = Settings::text('webhook_secret');
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

    private function enforceRateLimit(Request $request): void
    {
        if (! Settings::flag('rate_limit.enabled', false)) {
            return;
        }

        $maxAttempts = Settings::positiveInteger('rate_limit.max_attempts', 30);
        $decaySeconds = Settings::positiveInteger('rate_limit.decay_seconds', 60);
        $limiter = new RateLimiter(Cache::store(Settings::text('rate_limit.store', 'file')));
        $key = 'cpanel-deploy:'.hash('sha256', Settings::text('route_prefix', 'deploy').'|'.($request->ip() ?? 'unknown'));
        if ($limiter->tooManyAttempts($key, $maxAttempts)) {
            abort(429, 'Too many requests', ['Retry-After' => (string) $limiter->availableIn($key)]);
        }
        $limiter->hit($key, $decaySeconds);
    }
}
