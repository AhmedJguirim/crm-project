<?php

namespace App\Actions\Account;

use App\Enums\AccountSecurityEvent;
use App\Models\User;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityMails;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use SensitiveParameter;

class ChangePassword
{
    /**
     * Changes the signed-in user's password and ends their other sessions (SEC-28); this device stays signed in.
     * Used by the panel profile.
     */
    public function handle(User $user, #[SensitiveParameter] string $newPassword): void
    {
        $user->forceFill([
            'password' => $newPassword,
            'remember_token' => Str::random(60),
        ])->save();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $guard->hashPasswordForCookie($user->getAuthPassword()),
        );

        app(EndOtherSessions::class)->handle($user, session()->getId());

        SecurityLog::info('account.password_changed', $user);

        SecurityMails::send($user, AccountSecurityEvent::PasswordChanged);
    }
}
