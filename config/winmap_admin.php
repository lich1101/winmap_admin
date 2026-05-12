<?php

return [
    'server_usage_path' => env('SERVER_USAGE_PATH', '/'),

    'discovery' => [
        'roots' => array_values(array_filter(array_map(
            'trim',
            explode('|', env('DRUPAL_DISCOVERY_ROOTS', dirname(base_path()).'/winmap_new'))
        ))),
        'default_scheme' => env('DRUPAL_SITE_SCHEME', 'https'),
        'default_warning_threshold_percent' => (int) env('DEFAULT_WARNING_THRESHOLD_PERCENT', 85),
    ],

    'terminal' => [
        'enabled' => env('TERMINAL_ENABLED', true),
        'timeout' => (int) env('TERMINAL_TIMEOUT', 12),
        'max_output_bytes' => (int) env('TERMINAL_MAX_OUTPUT_BYTES', 60000),
        'allowed_commands' => array_values(array_filter(array_map('trim', explode(',', env('TERMINAL_ALLOWED_COMMANDS', 'pwd,ls,df,du,uptime,whoami,date'))))),
        'allowed_roots' => array_values(array_filter(array_map('trim', explode('|', env('TERMINAL_ALLOWED_ROOTS', base_path().'|'.dirname(base_path())))))),
    ],

    'drupal_auth' => [
        'settings_path' => env('DRUPAL_AUTH_SETTINGS_PATH'),
        'site_key' => env('DRUPAL_AUTH_SITE_KEY'),
        'host' => env('DRUPAL_AUTH_DB_HOST', '127.0.0.1'),
        'port' => env('DRUPAL_AUTH_DB_PORT', '3306'),
        'database' => env('DRUPAL_AUTH_DB_DATABASE'),
        'username' => env('DRUPAL_AUTH_DB_USERNAME'),
        'password' => env('DRUPAL_AUTH_DB_PASSWORD'),
        'socket' => env('DRUPAL_AUTH_DB_SOCKET', ''),
        'prefix' => env('DRUPAL_AUTH_DB_PREFIX', ''),
        'charset' => env('DRUPAL_AUTH_DB_CHARSET', 'utf8mb4'),
        'collation' => env('DRUPAL_AUTH_DB_COLLATION', 'utf8mb4_unicode_ci'),
        'password_inc_path' => env('DRUPAL_AUTH_PASSWORD_INC_PATH', dirname(base_path()).'/winmap_new/includes/password.inc'),
    ],

    'drupal_oauth' => [
        'client_id' => env('DRUPAL_OAUTH_CLIENT_ID', 'primary_client'),
        'client_secret' => env('DRUPAL_OAUTH_CLIENT_SECRET', 'RKGidZCB7Lit9FAGmaHl4Nt1tJKjS3upNR6aQ6'),
    ],
];
