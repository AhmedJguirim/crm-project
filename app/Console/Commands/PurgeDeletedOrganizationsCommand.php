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
     * its files and logo are deleted by `OrganizationObserver::forceDeleted()` once that transaction commits. The row
     * is read again with a lock before the force delete (SEC-52), so an organization restored after the list was
     * built (or after its cutoff moved) is kept and not counted.
     */
    public function handle(): int
    {
        $purged = 0;
        $cutoff = now()->subDays(Organization::DELETION_GRACE_DAYS);

        Organization::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->lazyById()
            ->each(function (Organization $organization) use (&$purged, $cutoff): void {
                $id = $organization->getKey();

                $done = DB::transaction(function () use ($id, $cutoff): bool {
                    $locked = Organization::onlyTrashed()
                        ->whereKey($id)
                        ->where('deleted_at', '<=', $cutoff)
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null) {
                        return false;
                    }

                    $locked->forceDelete();

                    return true;
                });

                if (! $done) {
                    return;
                }

                SecurityLog::warning('organization.purged', null, ['deleted_organization_id' => $id]);

                $purged++;
            });

        $this->comment("Purged {$purged} ".Str::plural('organization', $purged).'.');

        return self::SUCCESS;
    }
}
