<?php

namespace App\Support\Security;

use Filament\Auth\Events\Registered;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Turns the framework's sign-in and two-factor events into security log lines. It never reads the credentials of a
 * failed attempt nor the code of a replaced recovery code.
 */
class SecurityEventSubscriber
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
            PasswordReset::class => 'handlePasswordReset',
            Registered::class => 'handleRegistered',
            TwoFactorAuthenticationEnabled::class => 'handleTwoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'handleTwoFactorFailed',
            RecoveryCodeReplaced::class => 'handleRecoveryCodeReplaced',
            RecoveryCodesGenerated::class => 'handleRecoveryCodesGenerated',
        ];
    }

    public function handleLogin(Login $event): void
    {
        SecurityLog::info('auth.login', $event->user, ['guard' => $event->guard, 'remember' => (bool) $event->remember]);
    }

    public function handleFailed(Failed $event): void
    {
        SecurityLog::warning('auth.login_failed', $event->user, ['guard' => $event->guard]);
    }

    public function handleLogout(Logout $event): void
    {
        SecurityLog::info('auth.logout', $event->user, ['guard' => $event->guard]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        SecurityLog::info('auth.password_reset', $event->user);
    }

    public function handleRegistered(Registered $event): void
    {
        SecurityLog::info('auth.registered', $event->getUser());
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        SecurityLog::info('auth.two_factor_enabled', $event->user);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        SecurityLog::info('auth.two_factor_confirmed', $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        SecurityLog::warning('auth.two_factor_disabled', $event->user);
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        SecurityLog::warning('auth.two_factor_failed', $event->user);
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        SecurityLog::warning('auth.recovery_code_used', $event->user);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        SecurityLog::info('auth.recovery_codes_regenerated', $event->user);
    }
}
