<?php

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => array_filter(array_map('trim', explode(',', (string) env('LOG_STACK', 'daily,stderr')))),
            'ignore_exceptions' => false,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/lumen.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAYS', 14),
        ],

        // OldErpLoanSyncJob: storage/logs/old-erp-loan-sync-YYYY-MM-DD.log
        'old_erp_loan_sync' => [
            'driver' => 'daily',
            'path' => storage_path('logs/old-erp-loan-sync.log'),
            'level' => 'debug',
            'days' => 90,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
            'level' => env('LOG_LEVEL', 'debug'),
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
        ],
    ],
];

