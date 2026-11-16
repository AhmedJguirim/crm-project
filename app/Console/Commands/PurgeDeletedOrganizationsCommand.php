<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Security\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurgeDeletedOrganizationsCommand extends Command
{
    protected $signature = 'organizations:purge';

    protected $description = 'Delete for good the organizations deleted more than 30 days ago';

    /**
     * Every row of the organization goes through the `organization_id` cascades, in one transaction per organization;
     * its files and logo are deleted by `OrganizationObserver::forceDeleted()` once that transaction commits.
     */
    public function handle(): int
    {
        $purged = 0;

        Organization::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(Organization::DELETION_GRACE_DAYS))
            ->lazyById()
            ->each(function (Organization $organization) use (&$purged): void {
                $id = $organization->getKey();

                DB::transaction(fn () => $organization->forceDelete());

                SecurityLog::warning('organization.purged', null, ['deleted_organization_id' => $id]);

                $purged++;
            });

        $this->comment("Purged {$purged} ".Str::plural('organization', $purged).'.');

        return self::SUCCESS;
    }
}
