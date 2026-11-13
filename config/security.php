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
];
