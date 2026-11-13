<?php

return [
    'user_mails' => [
        /*
         * Mail the account's own address when its password or email changes, two-factor is turned off or a recovery
         * code is used. Leave on in production; false only where no mailer is configured.
         */
        'enabled' => env('SECURITY_USER_MAILS_ENABLED', true),
    ],
];
