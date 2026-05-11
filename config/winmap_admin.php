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
];
