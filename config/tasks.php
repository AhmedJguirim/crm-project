<?php

return [
    'notifications' => [
        'send_email' => env('TASK_NOTIFICATIONS_EMAIL_ENABLED', true),
        'daily_digest_enabled' => env('TASK_DAILY_DIGEST_ENABLED', true),
        'daily_digest_time' => env('TASK_DAILY_DIGEST_TIME', '08:00'),
    ],
];
