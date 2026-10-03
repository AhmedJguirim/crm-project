<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Jobs\SyncSegmentMembership;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->setTime(23, 5));
    Queue::fake();

    $this->segmentFor = function (Organization $organization, bool $published = true, bool $dated = true): Segment {
        $condition = $dated
            ? SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::WithinLastDays, ['days' => 7])
            : SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [Tag::factory()->for($organization)->create()->id]]);

        return Segment::factory()->for($organization)->state(['is_published' => $published])->withRules([
            new SegmentRuleData('rule-1', 'Rule', [$condition]),
        ])->create();
    };

    $this->queuedSegmentIds = fn (): array => Queue::pushed(SyncSegmentMembership::class)->map->segmentId->sort()->values()->all();
});

describe('the daily sync at local midnight', function () {
    it('only picks the organizations where it is local midnight', function () {
        $paris = ($this->segmentFor)(Organization::factory()->timezone('Europe/Paris')->create());
        ($this->segmentFor)(Organization::factory()->timezone('America/New_York')->create());

        $this->artisan('segments:sync', ['--frequency' => 'daily', '--local-hour' => 0])->assertSuccessful();

        expect(($this->queuedSegmentIds)())->toBe([$paris->id]);
    });

    it('handles half-hour offsets', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(18, 35));
        $kolkata = ($this->segmentFor)(Organization::factory()->timezone('Asia/Kolkata')->create());
        ($this->segmentFor)(Organization::factory()->timezone('Europe/Paris')->create());

        $this->artisan('segments:sync', ['--frequency' => 'daily', '--local-hour' => 0])->assertSuccessful();

        expect(($this->queuedSegmentIds)())->toBe([$kolkata->id]);
    });

    it('reaches every organization once a day, whatever its offset', function (string $timezone) {
        $organization = Organization::factory()->timezone($timezone)->create();
        $segment = ($this->segmentFor)($organization);
        $runs = 0;

        foreach (range(0, 23) as $hour) {
            Queue::fake();
            $this->travelTo(now()->setDate(2026, 3, 15)->setTime($hour, 5));
            $this->artisan('segments:sync', ['--frequency' => 'daily', '--local-hour' => 0])->assertSuccessful();
            $runs += in_array($segment->id, ($this->queuedSegmentIds)(), true) ? 1 : 0;
        }

        expect($runs)->toBe(1);
    })->with(['Europe/Paris', 'Asia/Kolkata', 'Asia/Kathmandu', 'Pacific/Kiritimati', 'America/St_Johns', 'UTC']);

    it('still honours the frequency and the organization options', function () {
        $paris = Organization::factory()->timezone('Europe/Paris')->create();
        $dated = ($this->segmentFor)($paris);
        ($this->segmentFor)($paris, dated: false);

        $this->artisan('segments:sync', ['--frequency' => 'daily', '--local-hour' => 0, '--organization' => $paris->id])->assertSuccessful();

        expect(($this->queuedSegmentIds)())->toBe([$dated->id]);
    });

    it('refuses an hour that does not exist', function (string $hour) {
        $this->artisan('segments:sync', ['--local-hour' => $hour])
            ->expectsOutputToContain("Invalid local hour `{$hour}`. Use a number from 0 to 23.")
            ->assertFailed();

        Queue::assertNothingPushed();
    })->with(['24', 'noon', '-1']);
});

describe('the schedule', function () {
    it('runs the date segments hourly at minute 5 and the task commands hourly', function () {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $needle): ?Event => $events->first(fn (Event $event): bool => str_contains((string) $event->command, $needle));

        expect($find('--local-hour=0')->expression)->toBe('5 * * * *')
            ->and($find('--local-hour=0')->command)->toContain('--frequency=daily')
            ->and($find('tasks:send-reminders')->expression)->toBe('0 * * * *')
            ->and($find('tasks:send-digest')->expression)->toBe('0 * * * *')
            ->and($find('--frequency=hourly')->expression)->toBe('0 * * * *');
    });
});

describe('changing the timezone of an organization', function () {
    it('syncs the published date segments again, and only those', function () {
        $organization = Organization::factory()->create();
        $dated = ($this->segmentFor)($organization);
        ($this->segmentFor)($organization, dated: false);
        ($this->segmentFor)($organization, published: false);
        ($this->segmentFor)(Organization::factory()->create());
        Queue::fake();

        $organization->update(['timezone' => 'Asia/Kolkata']);

        expect(($this->queuedSegmentIds)())->toBe([$dated->id]);
    });

    it('does nothing when something else changes', function () {
        $organization = Organization::factory()->create();
        ($this->segmentFor)($organization);
        Queue::fake();

        $organization->update(['name' => 'Renamed']);

        Queue::assertNothingPushed();
    });
});
