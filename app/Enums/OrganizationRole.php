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

    /** Every role can see every CRM page and record of the organization. */
    public function canView(): bool
    {
        return true;
    }

    /** Create and update contacts, companies, deals, tasks, activities, tags and invoices, and edit segment drafts. */
    public function canEdit(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Member], true);
    }

    /** Delete and restore, import contacts, publish segments, manage custom fields and company types. */
    public function canManage(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageMembers(): bool
    {
        return in_array($this, [self::Owner, self::Admin]);
    }
}
