<?php

namespace App\Notifications;

use App\Support\Security\SecurityLogDay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person running the app that a day of the security log crossed the alert threshold (SEC-44). It is sent to
 * an address, not to a user, and carries only counts, valid IP addresses and account ids.
 */
class SecurityOwnerAlertNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    private const int MAX_LOCKED_ACCOUNTS_LISTED = 10;

    private const int MAX_IPS_LISTED = 5;

    private const array SOURCE_LABELS = [
        'panel_account' => 'one account',
        'panel' => 'one IP on the sign-in page',
        'password.email' => 'password reset requests',
        'password.update' => 'password reset form',
        SecurityLogDay::OTHER_SOURCE => 'other',
    ];

    public function __construct(
        public SecurityLogDay $day,
        public int $threshold,
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
        $date = $this->day->day->format('j M Y');

        $mail = (new MailMessage)
            ->subject("Security alert: {$date} (UTC)")
            ->line('The security log of '.config('app.name')." for {$date} (UTC) crossed an alert threshold.")
            ->line("Failed sign-ins: {$this->day->failedLogins} (alert above {$this->threshold}).")
            ->line("Failed two-factor codes: {$this->day->failedTwoFactor}.")
            ->line($this->lockoutsLine());

        $lockedAccounts = $this->lockedAccountsLine();

        if ($lockedAccounts !== null) {
            $mail->line($lockedAccounts);
        }

        $addresses = $this->addressesLine();

        if ($addresses !== null) {
            $mail->line($addresses);
        }

        if ($this->day->trackingCapped) {
            $limit = number_format(SecurityLogDay::MAX_TRACKED);
            $mail->line("More than {$limit} addresses or accounts showed up; the lists above cover the first {$limit} seen.");
        }

        return $mail->line("The full lines are in {$this->day->fileName()} in the server's storage/logs folder.");
    }

    private function lockoutsLine(): string
    {
        $total = $this->day->lockouts();

        if ($total === 0) {
            return 'Lockouts: 0.';
        }

        $parts = [];

        foreach (self::SOURCE_LABELS as $source => $label) {
            $count = $this->day->lockoutsBySource[$source] ?? 0;

            if ($count > 0) {
                $parts[] = "{$label}: {$count}";
            }
        }

        return "Lockouts: {$total} (".implode(', ', $parts).').';
    }

    private function lockedAccountsLine(): ?string
    {
        $ids = $this->day->lockedUserIds;

        if ($ids === []) {
            return null;
        }

        $listed = array_map(fn (int $id): string => '#'.$id, array_slice($ids, 0, self::MAX_LOCKED_ACCOUNTS_LISTED));
        $more = count($ids) - self::MAX_LOCKED_ACCOUNTS_LISTED;

        return 'Locked accounts: '.implode(', ', $listed).($more > 0 ? " and {$more} more" : '').'.';
    }

    private function addressesLine(): ?string
    {
        $counts = $this->day->failedLoginsByIp;

        if ($counts === []) {
            return null;
        }

        $addresses = array_map('strval', array_keys($counts));
        usort($addresses, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        $listed = array_map(fn (string $ip): string => "{$ip} ({$counts[$ip]})", array_slice($addresses, 0, self::MAX_IPS_LISTED));

        return 'Most failed sign-ins by IP address: '.implode(', ', $listed).'.';
    }
}
