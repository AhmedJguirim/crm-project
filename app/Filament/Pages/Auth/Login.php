<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\InviteAcceptController;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\Security\KnownDevice;
use App\Support\Security\LoginLimits;
use App\Support\Security\SecurityLog;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    /**
     * Besides Filament's per-IP limit, an email address is locked for 15 minutes after 10 failed password checks from
     * any IP (SEC-42). A locked email never reaches the password check and the refusal is the same for an unknown email,
     * except for a known device (SEC-45): a browser where the account already signed in passes the lock with the right
     * password. That pass does not clear the account counter, so whoever caused the lock stays locked.
     */
    public function authenticate(): ?LoginResponse
    {
        $typedEmail = $this->data['email'] ?? null;

        $passesLockAsKnownDevice = false;

        if (is_string($typedEmail) && LoginLimits::isLocked($typedEmail)) {
            $passesLockAsKnownDevice = KnownDevice::passesLock($typedEmail);

            if (! $passesLockAsKnownDevice) {
                $minutes = (int) ceil(LoginLimits::secondsUntilUnlocked($typedEmail) / 60);

                Notification::make()
                    ->title('Too many login attempts')
                    ->body(NotificationText::plain('Please try again in '.$minutes.' '.Str::plural('minute', $minutes).'.'))
                    ->danger()
                    ->send();

                return null;
            }
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            $this->logFailedMultiFactorChallenge($exception);

            throw $exception;
        }

        if ($response) {
            /** @var User|null $user */
            $user = Filament::auth()->user();

            if ($user && $passesLockAsKnownDevice) {
                SecurityLog::info('auth.known_device_login', $user, ['source' => 'panel_account']);
            }

            if ($user && ! $passesLockAsKnownDevice) {
                LoginLimits::clear($user->email);
            }

            if ($user) {
                KnownDevice::remember($user->email);
            }

            if ($user && ! $user->onboarding_completed) {
                session(['show_onboarding' => true]);
            }

            $this->acceptPendingInviteIfExists($user);
        }

        return $response;
    }

    /**
     * Filament fires no event for a wrong authenticator code, so the failed challenge is logged here (SEC-22). Any
     * other validation error (wrong password, bad email) is already logged by the framework's failed-login event.
     */
    private function logFailedMultiFactorChallenge(ValidationException $exception): void
    {
        $isChallengeError = collect(array_keys($exception->errors()))
            ->contains(fn (string $key): bool => str_starts_with($key, 'data.multiFactor.'));

        if (! $isChallengeError) {
            return;
        }

        SecurityLog::warning('auth.two_factor_failed', $this->getUserUndertakingMultiFactorAuthentication(), ['source' => 'panel']);
    }

    /**
     * Every failed password check (wrong password, unknown email, panel access refused) counts toward the per-account
     * limit (SEC-42); the 10th one writes the lockout to the security log once, without the email. A wrong password on
     * a known device also counts toward that device's own limit (SEC-45).
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function fireFailedEvent(Guard $guard, ?Authenticatable $user, #[SensitiveParameter] array $credentials): void
    {
        parent::fireFailedEvent($guard, $user, $credentials);

        $email = $credentials['email'] ?? null;

        if (! is_string($email)) {
            return;
        }

        if (LoginLimits::recordFailure($email) === LoginLimits::MAX_FAILURES) {
            SecurityLog::warning('auth.lockout', $user, ['source' => 'panel_account']);
        }

        KnownDevice::recordFailure($email);
    }

    /**
     * Filament fires no event when the panel login is throttled, so the lockout is logged here (SEC-08).
     */
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        SecurityLog::warning('auth.lockout', context: ['source' => 'panel']);

        return parent::getRateLimitedNotification($exception);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $email = $data['email'];

        if (config('fortify.lowercase_usernames')) {
            $email = Str::lower($email);
        }

        return [
            'email' => $email,
            'password' => $data['password'],
        ];
    }

    private function acceptPendingInviteIfExists(User $user): void
    {
        $inviteId = session()->pull('invite_id');
        session()->forget('invite_email');

        if (! $inviteId) {
            return;
        }

        $invite = OrganizationInvite::query()->find($inviteId);

        // Only accept if the invite is still valid and the email matches
        if (! $invite || ! $invite->isValid() || ! $invite->isFor($user)) {
            return;
        }

        /** @var InviteAcceptController $controller */
        $controller = app(InviteAcceptController::class);
        $controller->acceptInvite($invite, $user);
    }
}
