<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->originalBase = base_path();
    $this->workspace = base_path('tests-temp/installer-'.Str::uuid());
    File::ensureDirectoryExists($this->workspace);
    app()->setBasePath($this->workspace);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBase);
    File::deleteDirectory($this->workspace);
});

it('installs root stubs without overwriting existing files unless forced', function (): void {
    File::put(base_path('index.php'), '<?php /* existing entrypoint */');
    $options = ['--only-root' => true, '--no-interaction' => true];
    expect(Artisan::call('cpanel-hosting:install', $options))->toBe(0)
        ->and(File::get(base_path('index.php')))->toBe('<?php /* existing entrypoint */')
        ->and(File::exists(base_path('.htaccess')))->toBeTrue();
    $example = File::get(base_path('.env.example'));
    expect(Artisan::call('cpanel-hosting:install', $options + ['--force' => true]))->toBe(0)
        ->and(File::get(base_path('index.php')))->toContain("require __DIR__.'/public/index.php'")
        ->and(File::glob(base_path('index.php.backup.*')))->toHaveCount(1)
        ->and(File::get(base_path('.env.example')))->toBe($example);
});

it('rejects conflicting installer options before changing files', function (): void {
    expect(Artisan::call('cpanel-hosting:install', ['--only-root' => true, '--only-config' => true, '--no-interaction' => true]))->toBe(2)
        ->and(File::exists(base_path('index.php')))->toBeFalse();
});

it('reports filesystem installation failures with a nonzero exit code', function (): void {
    File::ensureDirectoryExists(base_path('index.php'));
    expect(Artisan::call('cpanel-hosting:install', ['--only-root' => true, '--force' => true, '--no-interaction' => true]))->toBe(1);
});

it('writes interactive tokens literally and leaves deployment disabled unless requested', function (): void {
    $token = 'literal$1${APP_NAME}"\\value';
    $this->artisan('cpanel-hosting:install', ['--only-root' => true])
        ->expectsConfirmation('Update your .env deploy values now?', 'yes')
        ->expectsQuestion('Deploy token (leave empty to auto-generate)', $token)
        ->expectsQuestion('Deploy route prefix', 'operations/deploy')
        ->expectsConfirmation('Enable deploy routes now?', 'no')
        ->assertExitCode(0);
    expect(Dotenv::parse(File::get(base_path('.env'))))->toMatchArray([
        'CPANEL_DEPLOY_TOKEN' => $token,
        'CPANEL_DEPLOY_PREFIX' => 'operations/deploy',
        'CPANEL_DEPLOY_ENABLED' => 'false',
    ]);
});
