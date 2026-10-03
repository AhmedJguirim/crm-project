<?php

use App\Console\Commands\SyncSegmentsCommand;
use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\SegmentRefreshFrequency;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->org = Organization::factory()->create();
});

function refreshCondition(SegmentConditionType $type, SegmentOperator $operator, array $value = [], ?string $field = null): SegmentConditionData
{
    return SegmentConditionData::make($type, $field, $operator, $value);
}

function refreshSegment(Organization $organization, array $conditions, bool $published = true): Segment
{
    return Segment::factory()->for($organization)->state(['is_published' => $published])->withRules([
        new SegmentRuleData('rule-1', 'Rule', $conditions),
    ])->create();
}

function queuedSegmentIds(): array
{
    return Queue::pushed(SyncSegmentMembership::class)->map->segmentId->sort()->values()->all();
}

describe('condition classification', function () {
    it('knows whether a condition depends on the current time', function (SegmentConditionType $type, SegmentOperator $operator, array $value, ?SegmentRefreshFrequency $expected) {
        expect(refreshCondition($type, $operator, $value)->refreshFrequency())->toBe($expected);
    })->with([
        'attribute within last days' => [SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], SegmentRefreshFrequency::Daily],
        'custom field more than days ago' => [SegmentConditionType::CustomField, SegmentOperator::MoreThanDaysAgo, ['days' => 90], SegmentRefreshFrequency::Daily],
        'month is' => [SegmentConditionType::Attribute, SegmentOperator::MonthIs, ['month' => 5], null],
        'day and month is' => [SegmentConditionType::CustomField, SegmentOperator::DayAndMonthIs, ['day' => 3, 'month' => 5], null],
        'before a fixed date' => [SegmentConditionType::Attribute, SegmentOperator::Before, ['value' => '2026-01-01'], null],
        'tags' => [SegmentConditionType::Tags, SegmentOperator::HasAnyOf, ['values' => [1]], null],
        'company type' => [SegmentConditionType::Company, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [1]], null],
        'deal' => [SegmentConditionType::Deal, SegmentOperator::HasDeal, [], null],
        'activity without days' => [SegmentConditionType::Activity, SegmentOperator::HasHadActivity, [], null],
        'activity with days' => [SegmentConditionType::Activity, SegmentOperator::HasHadActivity, ['days' => 30], SegmentRefreshFrequency::Hourly],
        'no activity with days' => [SegmentConditionType::Activity, SegmentOperator::HasNotHadActivity, ['days' => 7], SegmentRefreshFrequency::Hourly],
        'last activity more than days ago' => [SegmentConditionType::Activity, SegmentOperator::LastActivityMoreThanDaysAgo, ['days' => 14], SegmentRefreshFrequency::Hourly],
    ]);
});

describe('segment classification', function () {
    it('takes the most frequent condition of all the rules', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules([
            new SegmentRuleData('rule-1', 'Dates', [refreshCondition(SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], ContactAttribute::CreatedAt->value)]),
            new SegmentRuleData('rule-2', 'Activity', [refreshCondition(SegmentConditionType::Activity, SegmentOperator::HasHadActivity, ['days' => 30])]),
        ])->create();

        expect($segment->refreshFrequency())->toBe(SegmentRefreshFrequency::Hourly);
    });

    it('is daily when only date conditions depend on the current time', function () {
        $segment = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], ContactAttribute::CreatedAt->value)]);

        expect($segment->refreshFrequency())->toBe(SegmentRefreshFrequency::Daily);
    });

    it('is never refreshed on a schedule when it only has static conditions', function () {
        $tag = Tag::factory()->for($this->org)->create();
        $segment = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Tags, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]);

        expect($segment->refreshFrequency())->toBeNull();
    });

    it('only looks at the published rules', function () {
        $tag = Tag::factory()->for($this->org)->create();
        $segment = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Tags, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]);
        $segment->update(['draft_rules' => [(new SegmentRuleData('rule-1', 'Dates', [
            refreshCondition(SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], ContactAttribute::CreatedAt->value),
        ]))->toArray()]]);

        expect($segment->fresh()->refreshFrequency())->toBeNull();
    });

    it('is not refreshed when it has no rules', function () {
        expect(Segment::factory()->for($this->org)->published()->create()->refreshFrequency())->toBeNull();
    });
});

describe('segments:sync', function () {
    beforeEach(function () {
        $tag = Tag::factory()->for($this->org)->create();
        $this->static = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Tags, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]);
        $this->monthly = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Attribute, SegmentOperator::MonthIs, ['month' => 5], ContactAttribute::CreatedAt->value)]);
        $this->dated = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], ContactAttribute::CreatedAt->value)]);
        $this->active = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Activity, SegmentOperator::HasHadActivity, ['days' => 30])]);
        $this->unpublished = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], ContactAttribute::CreatedAt->value)], published: false);
        Queue::fake([SyncSegmentMembership::class]);
    });

    it('queues only the hourly segments for an hourly run', function () {
        $this->artisan('segments:sync', ['--frequency' => 'hourly'])->expectsOutputToContain('Queued 1 segment syncs.')->assertSuccessful();

        expect(queuedSegmentIds())->toBe([$this->active->id]);
    });

    it('queues only the daily segments for a daily run', function () {
        $this->artisan('segments:sync', ['--frequency' => 'daily'])->assertSuccessful();

        expect(queuedSegmentIds())->toBe([$this->dated->id]);
    });

    it('queues every published segment without a frequency', function () {
        $this->artisan('segments:sync')->expectsOutputToContain('Queued 4 segment syncs.')->assertSuccessful();

        expect(queuedSegmentIds())->toBe(collect([$this->static, $this->monthly, $this->dated, $this->active])->map->id->sort()->values()->all());
    });

    it('can be narrowed to one organization', function () {
        $otherDated = refreshSegment(Organization::factory()->create(), [refreshCondition(SegmentConditionType::Attribute, SegmentOperator::WithinLastDays, ['days' => 30], ContactAttribute::CreatedAt->value)]);

        $this->artisan('segments:sync', ['--frequency' => 'daily', '--organization' => $this->org->id])->assertSuccessful();

        expect(queuedSegmentIds())->toBe([$this->dated->id])
            ->and(queuedSegmentIds())->not->toContain($otherDated->id);
    });

    it('refuses an unknown frequency', function () {
        $this->artisan('segments:sync', ['--frequency' => 'weekly'])
            ->expectsOutputToContain('Unknown frequency `weekly`. Use daily or hourly.')
            ->assertFailed();

        Queue::assertNotPushed(SyncSegmentMembership::class);
    });

    it('no longer has the temporary multi-select repair command', function () {
        expect(array_keys(Artisan::all()))->not->toContain('contacts:fix-multiselect-values');
    });

    it('streams the segments instead of loading them all', function () {
        $source = file_get_contents((new ReflectionClass(SyncSegmentsCommand::class))->getFileName());

        expect($source)->toContain('lazyById()')->not->toContain('->get(');
    });
});

describe('the schedule', function () {
    it('runs the hourly sync, and the daily one every hour for the organizations at local midnight', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'segments:sync'));
        $hourly = $events->first(fn ($event): bool => str_contains((string) $event->command, '--frequency=hourly'));
        $daily = $events->first(fn ($event): bool => str_contains((string) $event->command, '--frequency=daily'));

        expect($events)->toHaveCount(3)
            ->and($hourly->expression)->toBe('0 * * * *')
            ->and($hourly->withoutOverlapping)->toBeTrue()
            ->and($daily->command)->toContain('--local-hour=0')
            ->and($daily->expression)->toBe('5 * * * *')
            ->and($daily->withoutOverlapping)->toBeTrue();
    });

    it('re-syncs every published segment weekly as a safety net', function () {
        $weekly = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'segments:sync') && ! str_contains((string) $event->command, '--frequency'));

        expect($weekly)->not->toBeNull()
            ->and($weekly->expression)->toBe('30 3 * * 0')
            ->and($weekly->withoutOverlapping)->toBeTrue();
    });
});

describe('end to end', function () {
    it('drops a contact from an activity window segment once the activity is too old', function () {
        $segment = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Activity, SegmentOperator::HasHadActivity, ['days' => 30])]);
        $contact = Contact::factory()->for($this->org)->create();
        Activity::factory()->for($this->org)->for($contact)->create(['type' => ActivityType::Call, 'occurred_at' => now()->subDays(29)]);

        expect($segment->contacts()->withoutGlobalScope('organization')->pluck('contacts.id')->all())->toBe([$contact->id]);

        $this->travel(2)->days();
        $this->artisan('segments:sync', ['--frequency' => 'hourly'])->assertSuccessful();

        expect($segment->contacts()->withoutGlobalScope('organization')->count())->toBe(0);
    });

    it('leaves static segments alone on scheduled runs', function () {
        $tag = Tag::factory()->for($this->org)->create();
        $segment = refreshSegment($this->org, [refreshCondition(SegmentConditionType::Tags, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]);
        $contact = Contact::factory()->for($this->org)->create();
        $contact->tags()->attach($tag);
        SyncSegmentMembership::dispatchSync($segment->id);
        DB::table('contact_segment')->where('segment_id', $segment->id)->delete();

        $this->artisan('segments:sync', ['--frequency' => 'hourly'])->assertSuccessful();
        $this->artisan('segments:sync', ['--frequency' => 'daily'])->assertSuccessful();

        expect($segment->contacts()->withoutGlobalScope('organization')->count())->toBe(0);
    });
});

describe('last sync label', function () {
    it('says last full sync in the segments table and the segment details', function () {
        $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $organization = $user->personalOrganization();
        $this->actingAs($user);
        Filament::setTenant($organization);
        $segment = Segment::factory()->for($organization)->published()->create();

        Livewire::test(ListSegments::class)
            ->assertTableColumnExists('last_synced_at', fn (TextColumn $column): bool => $column->getLabel() === 'Last full sync');

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->assertSee('Last full sync')
            ->assertDontSee('Last synced');
    });
});
