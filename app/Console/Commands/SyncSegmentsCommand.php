<?php

namespace App\Console\Commands;

use App\Jobs\SyncSegmentMembership;
use App\Models\Segment;
use Illuminate\Console\Command;

class SyncSegmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'segments:sync {--organization= : Only sync the segments of this organization ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a membership sync for every published segment (keeps relative-date conditions up to date)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $segments = Segment::query()
            ->withoutGlobalScope('organization')
            ->where('is_published', true)
            ->when($this->option('organization'), fn ($query, $organizationId) => $query->where('organization_id', $organizationId))
            ->get(['id', 'name']);

        $segments->each(function (Segment $segment): void {
            $this->info("Queueing sync of segment `{$segment->name}` (#{$segment->id})...");

            SyncSegmentMembership::dispatch($segment->id);
        });

        $this->comment("Queued {$segments->count()} segment syncs.");

        return self::SUCCESS;
    }
}
