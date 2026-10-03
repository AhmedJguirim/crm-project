<?php

return [
    'notifications' => [
        'send_email' => env('TASK_NOTIFICATIONS_EMAIL_ENABLED', true),
        'daily_digest_enabled' => env('TASK_DAILY_DIGEST_ENABLED', true),

        /*
         * The reminders and the digest are checked every hour, and each organization gets them in the hour of its
         * own timezone given here. Only the hour part is used: "08:30" means 08:00.
         */
        'reminder_time' => env('TASK_REMINDER_TIME', '07:00'),
        'daily_digest_time' => env('TASK_DAILY_DIGEST_TIME', '08:00'),
    ],
];
