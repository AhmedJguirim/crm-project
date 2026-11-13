<?php

namespace App\Support\Security;

use App\Enums\AccountSecurityEvent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * The panel's authenticator app, with a security log line for each change and each used recovery code (SEC-22), and a mail
 * to the user when it is turned off or a recovery code is used (SEC-43).
 * Filament fires no event for these.
 */
class LoggedAppAuthentication extends AppAuthentication
{
    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        if (filled($secret)) {
            SecurityLog::info('auth.two_factor_enabled', $this->asAuthenticatable($user));

            return;
        }

        SecurityLog::warning('auth.two_factor_disabled', $this->asAuthenticatable($user));

        if ($user instanceof User) {
            SecurityMails::send($user, AccountSecurityEvent::TwoFactorDisabled);
        }
    }

    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $isValid = parent::verifyRecoveryCode($recoveryCode, $user);

        if ($isValid) {
            $codeOwner = $user ?? Filament::auth()->user();

            SecurityLog::warning('auth.recovery_code_used', $this->asAuthenticatable($codeOwner));

            if ($codeOwner instanceof User) {
                SecurityMails::send($codeOwner, AccountSecurityEvent::RecoveryCodeUsed);
            }
        }

        return $isValid;
    }

    /**
     * @return array<Action>
     */
    public function getActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->getName() === 'regenerateAppAuthenticationRecoveryCodes'
                ? $action->after(fn () => SecurityLog::info('auth.recovery_codes_regenerated', Filament::auth()->user()))
                : $action,
            parent::getActions(),
        );
    }

    private function asAuthenticatable(mixed $user): ?Authenticatable
    {
        return $user instanceof Authenticatable ? $user : null;
    }
}
