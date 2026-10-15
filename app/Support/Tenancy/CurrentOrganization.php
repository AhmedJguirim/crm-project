<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Filament\Facades\Filament;
use LogicException;

/**
 * The organization of the Filament panel's current tenant. It does not read the job or command context of
 * `TenantContext`; code outside the panel uses `TenantContext` or an explicit organization id.
 */
final class CurrentOrganization
{
    /**
     * The panel's tenant, or null when there is none.
     */
    public static function find(): ?Organization
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization ? $tenant : null;
    }

    /**
     * The panel's tenant.
     *
     * @throws LogicException when there is no current organization
     */
    public static function get(): Organization
    {
        return self::find() ?? throw new LogicException('There is no current organization.');
    }
}
