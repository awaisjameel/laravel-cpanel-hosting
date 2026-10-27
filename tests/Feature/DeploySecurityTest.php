<?php

declare(strict_types=1);

use Awaisjameel\LaravelCpanelHosting\Http\Middleware\EnsureDeployTokenIsValid;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

it('authenticates the entire github payload and rejects tampering', function (): void {
    config()->set('cpanel-hosting.webhook_secret', 'webhook-test-secret');
    $body = '{"ref":"refs/heads/main"}';
    $signature = 'sha256='.hash_hmac('sha256', $body, 'webhook-test-secret');
    $this->call('GET', '/deploy/health', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    $this->call('GET', '/deploy/health', [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => $signature, 'CONTENT_TYPE' => 'application/json'], $body.' ')->assertForbidden();
});

it('fails closed for malformed tokens and restricted client addresses', function (): void {
    $this->getJson('/deploy/health?token[]=bad')->assertForbidden();
    config()->set('cpanel-hosting.allowed_ips', '192.0.2.1, 192.0.2.2');
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')->getJson('/deploy/health')->assertForbidden();
    config()->set('cpanel-hosting.allowed_ips', ['127.0.0.1']);
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')->getJson('/deploy/health')->assertOk();
    config()->set('cpanel-hosting.allowed_ips', 123);
    $this->getJson('/deploy/health')->assertForbidden();
});

it('shares the rate limit across middleware instances and returns retry information', function (): void {
    config()->set('cpanel-hosting.rate_limit', ['enabled' => true, 'store' => 'file', 'max_attempts' => 1, 'decay_seconds' => 60]);
    $key = 'cpanel-deploy:'.hash('sha256', 'deploy|127.0.0.1');
    $limiter = new RateLimiter(Cache::store('file'));
    $limiter->clear($key);
    try {
        $request = Request::create('/deploy/health');
        $request->headers->set('X-Deploy-Token', 'test-deploy-token');
        $request->setUserResolver(fn () => null);
        expect((new EnsureDeployTokenIsValid)->handle($request, fn () => new Response('ok'))->getStatusCode())->toBe(200);
        $this->withHeader('X-Deploy-Token', 'test-deploy-token')->getJson('/deploy/health')->assertStatus(429)->assertHeader('Retry-After');
    } finally {
        $limiter->clear($key);
    }
});

it('keeps only the configured deployment prefix reachable during maintenance', function (): void {
    $middleware = new PreventRequestsDuringMaintenance(app());
    expect($middleware->getExcludedPaths())->toContain('deploy', 'deploy/*')->not->toContain('*');
    $this->withHeader('X-Deploy-Token', 'test-deploy-token')->getJson('/deploy/health')->assertOk();
});
