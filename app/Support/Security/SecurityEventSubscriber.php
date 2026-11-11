<?php

namespace App\Support\Security;

use Filament\Auth\Events\Registered;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Turns the framework's sign-in events into security log lines. It never reads the credentials of a failed attempt.
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
}
