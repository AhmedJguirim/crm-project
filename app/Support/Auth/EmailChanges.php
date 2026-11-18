<?php

namespace App\Support\Auth;

use App\Enums\AccountSecurityEvent;
use App\Models\User;
use App\Notifications\ConfirmNewEmailAddress;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityMails;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Holds a new email address as pending until the signed-in user opens the link mailed to it, then swaps it in (SEC-56).
 */
final class EmailChanges
{
    public const string NO_LONGER_VALID = 'This link is no longer valid. Ask for a new one below.';

    public const string TAKEN = 'This email address is now used by another account. Your email was not changed.';

    /**
     * Stores the address as pending and mails the link to it. A new request voids every older link.
     *
     * @return string|null the text to show when the link is refused, null when it was sent
     */
    public static function request(User $user, string $newEmail): ?string
    {
        $refusal = VerificationMails::claim($user);

        if ($refusal !== null) {
            return $refusal;
        }

        $user->forceFill(['pending_email' => $newEmail])->save();

        SecurityLog::info('account.email_change_requested', $user);

        self::sendLink($user);

        return null;
    }

    /**
     * Mails the link to the pending address again; nothing is sent when no change is pending.
     *
     * @return string|null the text to show when the link is refused, null otherwise
     */
    public static function resend(User $user): ?string
    {
        if ($user->pending_email === null) {
            return null;
        }

        $refusal = VerificationMails::claim($user);

        if ($refusal !== null) {
            return $refusal;
        }

        self::sendLink($user);

        return null;
    }

    /**
     * Drops the pending address, which voids its links.
     */
    public static function cancel(User $user): void
    {
        if ($user->pending_email === null) {
            return;
        }

        $user->forceFill(['pending_email' => null])->save();

        SecurityLog::info('account.email_change_cancelled', $user);
    }

    /**
     * Swaps the pending address in when the hash of the link matches it, under a row lock so that two opens of the
     * same link change the account once. The previous address is told only when it had been verified.
     *
     * @return string|null the text to show when the address was not swapped, null when the account now has it
     */
    public static function confirm(User $user, string $hash): ?string
    {
        $previous = null;

        try {
            $refusal = DB::transaction(function () use ($user, $hash, &$previous): ?string {
                $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $pending = $locked->pending_email;

                if ($pending === null || ! hash_equals(sha1($pending), $hash)) {
                    return hash_equals(sha1($locked->email), $hash) ? null : self::NO_LONGER_VALID;
                }

                if (User::query()->whereEmailIgnoringCase($pending)->whereKeyNot($locked->getKey())->exists()) {
                    $locked->forceFill(['pending_email' => null])->save();

                    return self::TAKEN;
                }

                $previous = ['email' => Str::lower($locked->email), 'verified' => $locked->hasVerifiedEmail()];

                $locked->forceFill(['email' => $pending, 'pending_email' => null, 'email_verified_at' => now()])->save();

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            return self::TAKEN;
        }

        if ($previous !== null) {
            $user->refresh();

            SecurityLog::info('account.email_changed', $user);

            if ($previous['verified']) {
                SecurityMails::send($user, AccountSecurityEvent::EmailChanged, $previous['email']);
            }
        }

        return $refusal;
    }

    private static function sendLink(User $user): void
    {
        $url = URL::temporarySignedRoute(
            'user-email.confirm',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->pending_email)],
        );

        Notification::route('mail', $user->pending_email)->notify(new ConfirmNewEmailAddress($url));
    }
}
