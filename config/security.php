<?php

return [
    'user_mails' => [
        /*
         * Mail the account's own address when its password or email changes, two-factor is turned off or a recovery
         * code is used. Leave on in production; false only where no mailer is configured.
         */
        'enabled' => env('SECURITY_USER_MAILS_ENABLED', true),
    ],
    'known_device_login' => [
        /*
         * A browser where the account already signed in passes the per-account sign-in lock (SEC-42) with the right
         * password. False makes every browser wait out the lock.
         */
        'enabled' => env('SECURITY_KNOWN_DEVICE_LOGIN_ENABLED', true),
    ],
    'owner_alerts' => [
        /*
         * Daily mail to the person running the app (not an organization owner) when yesterday's security log shows
         * more failed sign-ins than the threshold, or any lockout. Needs the address below to be set.
         */
        'enabled' => env('SECURITY_OWNER_ALERTS_ENABLED', true),
        'email' => env('SECURITY_OWNER_ALERTS_EMAIL'),
        'failed_logins_threshold' => (int) env('SECURITY_OWNER_ALERTS_FAILED_LOGINS', 20),
    ],
    'disk_alert' => [
        /*
         * Hourly mail to the person running the app when the disk that holds storage/ has less free space than the
         * warning or the critical level (in GB). Uses SECURITY_OWNER_ALERTS_EMAIL.
         */
        'enabled' => env('SECURITY_DISK_ALERT_ENABLED', true),
        'warn_free_gb' => (float) env('SECURITY_DISK_WARN_FREE_GB', 8),
        'critical_free_gb' => (float) env('SECURITY_DISK_CRITICAL_FREE_GB', 4),
    ],
    'queue_wait_alert' => [
        /*
         * Mail to the person running the app, at most once per queue an hour, when Horizon estimates that a queue
         * needs longer than its level (horizon.waits) to clear. Uses SECURITY_OWNER_ALERTS_EMAIL.
         */
        'enabled' => env('SECURITY_QUEUE_WAIT_ALERT_ENABLED', true),
    ],
];
