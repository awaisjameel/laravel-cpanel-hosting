<?php

return [
    'enabled' => env('CPANEL_DEPLOY_ENABLED', false),
    'token' => env('CPANEL_DEPLOY_TOKEN'),
    'webhook_secret' => env('CPANEL_DEPLOY_WEBHOOK_SECRET'),
    'route_prefix' => env('CPANEL_DEPLOY_PREFIX', 'deploy'),
    'allowed_ips' => env('CPANEL_DEPLOY_ALLOWED_IPS'),

    'rate_limit' => [
        'store' => env('CPANEL_DEPLOY_RATE_LIMIT_STORE', 'file'),
        'enabled' => env('CPANEL_DEPLOY_RATE_LIMIT_ENABLED', false),
        'max_attempts' => env('CPANEL_DEPLOY_RATE_LIMIT_MAX_ATTEMPTS', 30),
        'decay_seconds' => env('CPANEL_DEPLOY_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

    'sync_env' => [
        'source' => env('CPANEL_SYNC_ENV_SOURCE', '.env.server'),
        'target' => env('CPANEL_SYNC_ENV_TARGET', '.env'),
        'backup' => env('CPANEL_SYNC_ENV_BACKUP', true),
        'required_keys' => ['APP_KEY'],
    ],

    'storage_link' => [
        'prefer_symlink' => env('CPANEL_STORAGE_LINK_PREFER_SYMLINK', true),
        'fallback_copy' => env('CPANEL_STORAGE_LINK_FALLBACK_COPY', true),
        'source' => env('CPANEL_STORAGE_LINK_SOURCE', 'app/public'),
        'public_path' => env('CPANEL_STORAGE_LINK_PUBLIC_PATH', 'storage'),
    ],

    'mysql_legacy_compat' => [
        'enabled' => env('CPANEL_MYSQL_LEGACY_COMPAT', true),
        'length' => env('CPANEL_MYSQL_LEGACY_LENGTH', 191),
        'all_connections' => env('CPANEL_MYSQL_LEGACY_ALL_CONNECTIONS', false),
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
        'stop_on_failure' => env('CPANEL_DEPLOY_STOP_ON_FAILURE', true),
    ],

    'maintenance' => [
        'secret' => env('APP_MAINTENANCE_SECRET'),
    ],

    'logging' => [
        'channel' => env('CPANEL_DEPLOY_LOG_CHANNEL', 'deploy'),
    ],
];
