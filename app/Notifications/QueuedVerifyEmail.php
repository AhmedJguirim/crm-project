<?php

namespace App\Notifications;

use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/**
 * The verification mail, sent from the queue and encrypted in the queue payload because it holds a signed link.
 */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldBeEncrypted {}
