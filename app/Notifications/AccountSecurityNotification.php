<?php

namespace App\Notifications;

use App\Enums\AccountSecurityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner of an account that its password, email or two-factor setup changed (SEC-43). It carries no secret and
 * no model, so it can also go to the previous email address of an account.
 */
class AccountSecurityNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public AccountSecurityEvent $event,
        public CarbonImmutable $occurredAt,
        public ?string $ipAddress,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = $this->ipAddress === null ? '' : ' from IP address '.$this->ipAddress;

        $mail = (new MailMessage)
            ->subject($this->event->subject())
            ->line($this->event->summary().' on '.$this->occurredAt->format('j M Y, H:i').' UTC'.$from.'.');

        if ($this->event === AccountSecurityEvent::RecoveryCodeUsed) {
            $mail->line("That code can't be used again.");
        }

        $mail->line('If this was you, you can ignore this email.');

        if ($this->event === AccountSecurityEvent::EmailChanged) {
            return $mail->line("If it wasn't, someone else may have access to your account, and this address can no longer be used to sign in. Write to ".config('mail.from.address').' to recover it.');
        }

        return $mail
            ->line(match ($this->event) {
                AccountSecurityEvent::PasswordChanged => "If it wasn't, reset your password now: someone else may have access to your account.",
                AccountSecurityEvent::TwoFactorDisabled => "If it wasn't, reset your password now and turn two-factor authentication back on.",
                default => "If it wasn't, reset your password now and generate new recovery codes.",
            })
            ->action('Reset password', route('password.request'));
    }
}
