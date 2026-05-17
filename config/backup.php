<?php

return [
    'database' => [
        'disk' => env('DB_BACKUP_DISK', 'local'),
        'directory' => env('DB_BACKUP_DIRECTORY', 'backups/postgres'),
        'keep_days' => (int) env('DB_BACKUP_KEEP_DAYS', 7),
        'timeout_seconds' => (int) env('DB_BACKUP_TIMEOUT_SECONDS', 1200),
    ],
];
