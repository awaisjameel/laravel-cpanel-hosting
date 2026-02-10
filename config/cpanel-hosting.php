<?php

$readEnv = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false) {
        return $default;
    }

    if (! is_string($value)) {
        return $value;
    }

    return match (strtolower(trim($value))) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
};

return [
    'enabled' => $readEnv('CPANEL_DEPLOY_ENABLED', false),
    'token' => $readEnv('CPANEL_DEPLOY_TOKEN'),
    'webhook_secret' => $readEnv('CPANEL_DEPLOY_WEBHOOK_SECRET'),
    'route_prefix' => $readEnv('CPANEL_DEPLOY_PREFIX', 'deploy'),
    'allowed_ips' => $readEnv('CPANEL_DEPLOY_ALLOWED_IPS'),

    'rate_limit' => [
        'enabled' => $readEnv('CPANEL_DEPLOY_RATE_LIMIT_ENABLED', false),
        'max_attempts' => $readEnv('CPANEL_DEPLOY_RATE_LIMIT_MAX_ATTEMPTS', 30),
        'decay_seconds' => $readEnv('CPANEL_DEPLOY_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

    'sync_env' => [
        'source' => $readEnv('CPANEL_SYNC_ENV_SOURCE', '.env.server'),
        'target' => $readEnv('CPANEL_SYNC_ENV_TARGET', '.env'),
        'backup' => $readEnv('CPANEL_SYNC_ENV_BACKUP', true),
        'required_keys' => ['APP_KEY'],
    ],

    'storage_link' => [
        'prefer_symlink' => $readEnv('CPANEL_STORAGE_LINK_PREFER_SYMLINK', true),
        'fallback_copy' => $readEnv('CPANEL_STORAGE_LINK_FALLBACK_COPY', true),
        'source' => $readEnv('CPANEL_STORAGE_LINK_SOURCE', 'app/public'),
        'public_path' => $readEnv('CPANEL_STORAGE_LINK_PUBLIC_PATH', 'storage'),
    ],

    'mysql_legacy_compat' => [
        'enabled' => $readEnv('CPANEL_MYSQL_LEGACY_COMPAT', true),
        'length' => $readEnv('CPANEL_MYSQL_LEGACY_LENGTH', 191),
        'all_connections' => $readEnv('CPANEL_MYSQL_LEGACY_ALL_CONNECTIONS', false),
    ],

    'pipeline' => [
        'default_steps' => [
            'sync-env',
            'maintenance-down',
            'optimize-clear',
            'migrate',
            'cache',
            'storage-link',
            'maintenance-up',
        ],
        'stop_on_failure' => $readEnv('CPANEL_DEPLOY_STOP_ON_FAILURE', true),
    ],

    'maintenance' => [
        'secret' => $readEnv('APP_MAINTENANCE_SECRET'),
    ],

    'logging' => [
        'channel' => $readEnv('CPANEL_DEPLOY_LOG_CHANNEL', 'deploy'),
    ],
];
