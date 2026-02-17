<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Owner => 'danger',
            self::Admin => 'warning',
            self::Member => 'success',
            self::Viewer => 'gray',
        };
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }
}
