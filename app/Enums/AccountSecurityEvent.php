<?php

namespace App\Enums;

enum AccountSecurityEvent: string
{
    case PasswordChanged = 'password_changed';
    case EmailChanged = 'email_changed';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodeUsed = 'recovery_code_used';

    public function subject(): string
    {
        return match ($this) {
            self::PasswordChanged => 'Your password was changed',
            self::EmailChanged => 'The email address of your account was changed',
            self::TwoFactorDisabled => 'Two-factor authentication was turned off',
            self::RecoveryCodeUsed => 'A two-factor recovery code was used',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::PasswordChanged => 'The password of your account was changed',
            self::EmailChanged => 'The email address of your account was changed from this address to a different one',
            self::TwoFactorDisabled => 'Two-factor authentication was turned off for your account',
            self::RecoveryCodeUsed => 'One of your two-factor recovery codes was used',
        };
    }
}
