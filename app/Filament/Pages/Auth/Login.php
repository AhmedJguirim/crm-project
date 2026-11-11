<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\InviteAcceptController;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Support\Security\SecurityLog;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            $this->logFailedMultiFactorChallenge($exception);

            throw $exception;
        }

        if ($response) {
            /** @var User|null $user */
            $user = Filament::auth()->user();

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
