<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Caps the invitation mails one organization and one user can send, and how often one invitation can be resent (SEC-04),
 * so invitations cannot be used as a spam relay. The counters live in the cache through the RateLimiter and are
 * incremented first, then compared, so parallel requests cannot pass the same count; a window starts at the first
 * mail counted.
 */
final class InvitationLimits
{
    public const int ORGANIZATION_PER_DAY = 20;

    public const int USER_PER_DAY = 50;

    public const int WINDOW_SECONDS = 86400;

    public const int RESEND_COOLDOWN_SECONDS = 600;

    public const int ORGANIZATIONS_PER_USER = 10;

    /**
     * Counts one invitation mail (new or resent) against the organization and the user, unless a cap is reached.
     * Each counter is incremented first and the returned count is compared, so parallel requests never pass on the
     * same count. A refused call gives back what it counted.
     *
     * @return string|null the text to show when the mail is refused, null when it was counted
     */
    public static function claim(Organization $organization, User $user): ?string
    {
        $organizationKey = "invitations:organization:{$organization->id}";
        $userKey = "invitations:user:{$user->id}";

        if (RateLimiter::increment($organizationKey, self::WINDOW_SECONDS) > self::ORGANIZATION_PER_DAY) {
            RateLimiter::decrement($organizationKey, self::WINDOW_SECONDS);

            return 'This organization has sent '.self::ORGANIZATION_PER_DAY.' invitations in the last 24 hours. Try again later.';
        }

        if (RateLimiter::increment($userKey, self::WINDOW_SECONDS) > self::USER_PER_DAY) {
            RateLimiter::decrement($userKey, self::WINDOW_SECONDS);
            RateLimiter::decrement($organizationKey, self::WINDOW_SECONDS);

            return 'You have sent '.self::USER_PER_DAY.' invitations in the last 24 hours. Try again later.';
        }

        return null;
    }

    /**
     * Claims the resend cooldown of this invitation: true for the one caller whose increment created the counter,
     * false when it was resent less than 10 minutes ago (or a parallel request claimed it first). The counter keeps
     * the lifetime of its first increment, so refused calls do not extend the cooldown.
     */
    public static function claimResend(OrganizationInvite $invite): bool
    {
        return RateLimiter::increment(self::resendKey($invite), self::RESEND_COOLDOWN_SECONDS) === 1;
    }

    /**
     * Gives the resend cooldown of this invitation back, when the resend was refused after it was claimed.
     */
    public static function releaseResend(OrganizationInvite $invite): void
    {
        RateLimiter::clear(self::resendKey($invite));
    }

    /**
     * Whether the user already created the most organizations they may.
     */
    public static function organizationLimitReached(User $user): bool
    {
        return Organization::query()
            ->where('created_by', $user->id)
            ->where('personal_team', false)
            ->count() >= self::ORGANIZATIONS_PER_USER;
    }

    private static function resendKey(OrganizationInvite $invite): string
    {
        return "invitations:resend:{$invite->id}";
    }
}
