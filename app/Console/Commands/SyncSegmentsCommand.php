<?php

namespace App\Console\Commands;

use App\Enums\SegmentRefreshFrequency;
use App\Jobs\SyncSegmentMembership;
use App\Models\Organization;
use App\Models\Segment;
use Illuminate\Console\Command;

class SyncSegmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'segments:sync
        {--organization= : Only sync the segments of this organization ID}
        {--frequency= : Only sync segments that must be refreshed daily or hourly because they depend on the current date}
        {--local-hour= : Only sync the segments of organizations where it is this hour (0-23) in their timezone}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a membership sync for published segments (all of them, or only the ones depending on the current date)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $frequency = $this->option('frequency');

        if ($frequency !== null && SegmentRefreshFrequency::tryFrom($frequency) === null) {
            $this->error("Unknown frequency `{$frequency}`. Use daily or hourly.");

            return self::FAILURE;
        }

        $localHour = $this->option('local-hour');

        if ($localHour !== null && (! ctype_digit((string) $localHour) || (int) $localHour > 23)) {
            $this->error("Invalid local hour `{$localHour}`. Use a number from 0 to 23.");

            return self::FAILURE;
        }

        $frequency = $frequency === null ? null : SegmentRefreshFrequency::from($frequency);
        $queued = 0;

        Segment::query()
            ->withoutGlobalScope('organization')
            ->where('is_published', true)
            ->when($this->option('organization'), fn ($query, $organizationId) => $query->where('organization_id', $organizationId))
            ->when($localHour !== null, fn ($query) => $query->whereIn('organization_id', $this->organizationIdsAtLocalHour((int) $localHour)))
            ->select(['id', 'organization_id', 'name', 'rules'])
            ->lazyById()
            ->filter(fn (Segment $segment): bool => $frequency === null || $segment->refreshFrequency() === $frequency)
            ->each(function (Segment $segment) use (&$queued): void {
                $this->info("Queueing sync of segment `{$segment->name}` (#{$segment->id})...");

                SyncSegmentMembership::dispatch($segment->id);

                $queued++;
            });

        $this->comment("Queued {$queued} segment syncs.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function organizationIdsAtLocalHour(int $hour): array
    {
        return Organization::query()
            ->select(['id', 'timezone'])
            ->lazyById()
            ->filter(fn (Organization $organization): bool => $organization->isLocalHour($hour))
            ->map(fn (Organization $organization): int => $organization->getKey())
            ->values()
            ->all();
    }
}
