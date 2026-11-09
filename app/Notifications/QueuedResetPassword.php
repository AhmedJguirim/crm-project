<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The reset password mail, sent from the queue so the forgot-password answer does not wait for the mail server.
 * Encrypted in the queue payload because it holds the plain reset token.
 */
class QueuedResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
}
