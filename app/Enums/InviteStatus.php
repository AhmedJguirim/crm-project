<?php

namespace App\Enums;

enum InviteStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /** Get a human-readable label for the status. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }

    /** Get the Filament badge color for this status. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Expired => 'gray',
            self::Revoked => 'danger',
        };
    }

    /** Whether this invite can still be accepted. */
    public function isUsable(): bool
    {
        return $this === self::Pending;
    }
}
