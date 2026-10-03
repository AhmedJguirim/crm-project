<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function leadsRule(): SegmentRuleData
{
    return new SegmentRuleData('rule-1', 'Leads', [
        SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
    ]);
}

function segmentForTag(Organization $organization, Tag $tag, bool $published = true): Segment
{
    return Segment::factory()->for($organization)->state(['is_published' => $published])->withRules([
        new SegmentRuleData('rule-1', 'Tagged', [
            SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        ]),
    ])->create();
}

beforeEach(function () {
    $this->org = Organization::factory()->create();
});

describe('SyncSegmentMembership', function () {
    it('adds matching contacts and removes the ones that stopped matching', function () {
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $partner = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['is_syncing' => true]);
        $segment->contacts()->attach($partner);

        SyncSegmentMembership::dispatchSync($segment->id);

        expect($segment->contacts()->pluck('contacts.id')->all())->toBe([$lead->id])
            ->and($segment->fresh()->is_syncing)->toBeFalse()
            ->and($segment->fresh()->last_synced_at)->not->toBeNull();
    });

    it('keeps the joined at date of contacts that still match', function () {
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        $segment->contacts()->attach($lead, ['created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);

        SyncSegmentMembership::dispatchSync($segment->id);
        SyncSegmentMembership::dispatchSync($segment->id);

        $pivot = $segment->contacts()->first()->pivot;

        expect($segment->contacts()->count())->toBe(1)
            ->and($pivot->created_at->year)->toBe(now()->subYear()->year);
    });

    it('does not touch unpublished segments', function () {
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->withRules([leadsRule()])->create();

        SyncSegmentMembership::dispatchSync($segment->id);

        expect($segment->contacts()->count())->toBe(0);
    });

    it('notifies the user who published the segment', function () {
        $user = User::factory()->create();
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();

        SyncSegmentMembership::dispatchSync($segment->id, $user->id);

        expect($user->notifications()->count())->toBe(1)
            ->and($user->notifications()->first()->data['title'])->toBe('Segment setup complete');
    });

    it('is queued for every published segment by the sync command', function () {
        Queue::fake();
        $published = Segment::factory()->for($this->org)->published()->create();
        Segment::factory()->for($this->org)->create();

        $this->artisan('segments:sync')->assertSuccessful();

        Queue::assertPushed(SyncSegmentMembership::class, 1);
        Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $published->id);
    });
});

describe('ResyncContactSegments', function () {
    it('moves a contact in and out of segments when it changes', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        $contact = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);

        expect($contact->segments()->pluck('segments.id')->all())->toBe([$segment->id]);

        $contact->update(['status' => ContactStatus::Partner]);

        expect($contact->segments()->count())->toBe(0);
    });

    it('removes soft-deleted contacts from segments', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        $contact = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);

        $contact->delete();

        expect($segment->contacts()->withTrashed()->count())->toBe(0);

        $contact->restore();

        expect($segment->contacts()->count())->toBe(1);
    });

    it('is dispatched when activities and deals change', function () {
        Segment::factory()->for($this->org)->published()->create();
        $contact = Contact::factory()->for($this->org)->create();
        Queue::fake();

        Activity::factory()->for($this->org)->for($contact)->create(['type' => ActivityType::Call]);
        Deal::factory()->for($this->org)->for($contact)->create(['status' => DealStatus::Open]);

        Queue::assertPushed(ResyncContactSegments::class, fn (ResyncContactSegments $job): bool => $job->contactId === $contact->id);
    });

    it('is not dispatched when the organization has no published segment', function () {
        Segment::factory()->for($this->org)->create();
        Queue::fake();

        Contact::factory()->for($this->org)->create();

        Queue::assertNotPushed(ResyncContactSegments::class);
    });
});

describe('ResyncContactSegments evaluation', function () {
    beforeEach(function () {
        $this->tags = Tag::factory()->for($this->org)->count(5)->create();
        $this->segments = $this->tags->map(fn (Tag $tag): Segment => segmentForTag($this->org, $tag));
        Queue::fake([ResyncContactSegments::class]);
        $this->contact = Contact::factory()->for($this->org)->create();
    });

    function resyncContact(Contact $contact): void
    {
        (new ResyncContactSegments($contact->id))->handle();
    }

    /** @return Collection<int, array{query: string, bindings: array<int, mixed>, time: float}> */
    function segmentEvaluationQueries(): Collection
    {
        return collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains(strtolower($log['query']), 'as "segment_'));
    }

    function memberSegmentIds(Contact $contact): array
    {
        return DB::table('contact_segment')->where('contact_id', $contact->id)->orderBy('segment_id')->pluck('segment_id')->all();
    }

    it('leaves the contact in exactly the segments it matches after a change', function () {
        $this->contact->tags()->sync([$this->tags[0]->id, $this->tags[1]->id]);
        resyncContact($this->contact);

        expect(memberSegmentIds($this->contact))->toBe([$this->segments[0]->id, $this->segments[1]->id]);

        $this->contact->segments()->attach($this->segments[3]);
        $this->contact->tags()->sync([$this->tags[0]->id, $this->tags[2]->id, $this->tags[4]->id]);
        resyncContact($this->contact);

        expect(memberSegmentIds($this->contact))->toBe([$this->segments[0]->id, $this->segments[2]->id, $this->segments[4]->id]);
    });

    it('evaluates all segments in a single query', function () {
        $this->contact->tags()->sync([$this->tags[0]->id]);

        DB::enableQueryLog();
        resyncContact($this->contact);
        $evaluations = segmentEvaluationQueries();

        expect($evaluations)->toHaveCount(1)
            ->and(substr_count(strtolower($evaluations->first()['query']), 'as "segment_'))->toBe(5);
    });

    it('keeps the joined at date of segments that still match', function () {
        $this->contact->tags()->sync([$this->tags[0]->id]);
        $this->contact->segments()->attach($this->segments[0], ['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);

        resyncContact($this->contact);

        $joinedAt = DB::table('contact_segment')->where('contact_id', $this->contact->id)->where('segment_id', $this->segments[0]->id)->value('created_at');

        expect(now()->parse($joinedAt)->isSameDay(now()->subDays(30)))->toBeTrue();
    });

    it('does not fail when the pivot row already exists', function () {
        $this->contact->tags()->sync([$this->tags[1]->id]);
        DB::table('contact_segment')->insert([
            'segment_id' => $this->segments[1]->id,
            'contact_id' => $this->contact->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        resyncContact($this->contact);

        expect(memberSegmentIds($this->contact))->toBe([$this->segments[1]->id]);
    });

    it('removes a trashed contact from every segment', function () {
        $this->contact->tags()->sync([$this->tags[0]->id, $this->tags[1]->id]);
        resyncContact($this->contact);
        $this->contact->delete();

        DB::enableQueryLog();
        resyncContact($this->contact);
        $evaluations = segmentEvaluationQueries();

        expect(memberSegmentIds($this->contact))->toBe([])
            ->and($evaluations)->toHaveCount(0);
    });

    it('leaves the memberships of other organizations untouched', function () {
        $otherOrg = Organization::factory()->create();
        $otherTag = Tag::factory()->for($otherOrg)->create();
        $otherSegment = segmentForTag($otherOrg, $otherTag);
        $otherContact = Contact::factory()->for($otherOrg)->create();
        $otherContact->tags()->sync([$otherTag->id]);
        $otherSegment->contacts()->attach($otherContact);
        $this->contact->tags()->sync([$this->tags[0]->id]);

        resyncContact($this->contact);

        expect(memberSegmentIds($otherContact))->toBe([$otherSegment->id])
            ->and(memberSegmentIds($this->contact))->toBe([$this->segments[0]->id]);
    });

    it('runs no evaluation query when the organization has only unpublished segments', function () {
        $org = Organization::factory()->create();
        segmentForTag($org, Tag::factory()->for($org)->create(), published: false);
        $contact = Contact::factory()->for($org)->create();

        DB::enableQueryLog();
        resyncContact($contact);
        $evaluations = segmentEvaluationQueries();

        expect($evaluations)->toHaveCount(0)
            ->and(memberSegmentIds($contact))->toBe([]);
    });
});

describe('overlapping syncs', function () {
    function partnersRule(): SegmentRuleData
    {
        return new SegmentRuleData('rule-2', 'Partners', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Partner->value]),
        ]);
    }

    function membersOfSegment(Segment $segment): array
    {
        return $segment->contacts()->pluck('contacts.id')->sort()->values()->all();
    }

    function overlapLock(SyncSegmentMembership $job): Lock
    {
        return Cache::lock((new WithoutOverlapping((string) $job->segmentId))->getLockKey($job), 900);
    }

    function runQueueWorkerOnce(): void
    {
        test()->artisan('queue:work', ['connection' => 'database', '--queue' => 'segments', '--once' => true, '--tries' => 1])->assertSuccessful();
    }

    it('is guarded by an expiring overlap lock per segment', function () {
        $job = new SyncSegmentMembership(42);
        $middleware = $job->middleware();

        expect($middleware)->toHaveCount(1)
            ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
            ->and($middleware[0]->key)->toBe('42')
            ->and($middleware[0]->releaseAfter)->toBe(15)
            ->and($middleware[0]->expiresAfter)->toBe(660)
            ->and($job->timeout)->toBe(600)
            ->and($job->timeout)->toBeLessThan($middleware[0]->expiresAfter)
            ->and($middleware[0]->expiresAfter)->toBeLessThanOrEqual(660);
    });

    it('times out before the queue considers it lost', function () {
        $job = new SyncSegmentMembership(42);

        expect($job->connection)->toBeNull()
            ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
    });

    it('is failed right away when the worker times out', function () {
        config(['queue.default' => 'database']);
        $segment = Segment::factory()->for($this->org)->published()->create(['is_syncing' => true]);

        SyncSegmentMembership::dispatch($segment->id);
        $queued = Queue::connection('database')->pop('segments');

        expect($queued->shouldFailOnTimeout())->toBeTrue()
            ->and((new SyncSegmentMembership($segment->id))->failOnTimeout)->toBeTrue();
    });

    it('is retried by time and not by attempts', function () {
        $this->freezeTime();

        expect((new SyncSegmentMembership(42))->retryUntil()->getTimestamp())->toBe(now()->addMinutes(30)->getTimestamp());
    });

    it('waits, instead of failing, while another sync of the segment is running', function () {
        config(['queue.default' => 'database']);
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        $lock = overlapLock(new SyncSegmentMembership($segment->id));
        $lock->get();

        SyncSegmentMembership::dispatch($segment->id);
        runQueueWorkerOnce();

        $waiting = DB::table('jobs')->first();

        expect($waiting)->not->toBeNull()
            ->and($waiting->attempts)->toBe(1)
            ->and($waiting->available_at)->toBeGreaterThan(now()->getTimestamp())
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(membersOfSegment($segment))->toBe([]);

        $lock->release();
        $this->travel(20)->seconds();
        runQueueWorkerOnce();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(membersOfSegment($segment))->toBe([$lead->id]);
    });

    it('does not block syncs of other segments', function () {
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $running = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        $other = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        overlapLock(new SyncSegmentMembership($running->id))->get();

        SyncSegmentMembership::dispatchSync($other->id);

        expect(membersOfSegment($other))->toBe([$lead->id])
            ->and(membersOfSegment($running))->toBe([]);
    });

    it('locks the segment row before writing the membership', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);

        DB::enableQueryLog();
        SyncSegmentMembership::dispatchSync($segment->id);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query))->values();

        $lockedAt = $queries->search(fn (string $query): bool => str_contains($query, 'from "segments"') && str_contains($query, 'for update'));
        $writtenAt = $queries->search(fn (string $query): bool => str_contains($query, 'contact_segment') && (str_starts_with($query, 'delete') || str_starts_with($query, 'insert')));

        expect($lockedAt)->not->toBeFalse()
            ->and($writtenAt)->not->toBeFalse()
            ->and($lockedAt)->toBeLessThan($writtenAt);
    });

    it('commits the members, the sync flag and the timestamp together', function () {
        $partner = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['is_syncing' => true]);
        $segment->contacts()->attach($partner);
        $syncedAt = $segment->fresh()->last_synced_at;
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "segments"')) {
                throw new RuntimeException('Boom');
            }
        });

        expect(fn () => (new SyncSegmentMembership($segment->id))->handle())->toThrow(RuntimeException::class);

        expect(membersOfSegment($segment))->toBe([$partner->id])
            ->and($segment->fresh()->is_syncing)->toBeTrue()
            ->and($segment->fresh()->last_synced_at->equalTo($syncedAt))->toBeTrue();
    });

    it('ends with the members of the newest rules after back-to-back syncs', function () {
        $alice = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $bob = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['is_syncing' => true]);

        SyncSegmentMembership::dispatchSync($segment->id);
        expect(membersOfSegment($segment))->toBe([$alice->id]);

        $segment->update(['rules' => [partnersRule()->toArray()], 'is_syncing' => true]);
        SyncSegmentMembership::dispatchSync($segment->id);

        expect(membersOfSegment($segment))->toBe([$bob->id])
            ->and($segment->fresh()->is_syncing)->toBeFalse()
            ->and($segment->fresh()->last_synced_at)->not->toBeNull();
    });

    it('leaves missing and unpublished segments alone', function () {
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->withRules([leadsRule()])->create();
        $segment->contacts()->attach($lead);

        expect(fn () => SyncSegmentMembership::dispatchSync(999999))->not->toThrow(Throwable::class);

        SyncSegmentMembership::dispatchSync($segment->id);

        expect(membersOfSegment($segment))->toBe([$lead->id]);
    });
});

describe('too many days in a stored condition', function () {
    it('is skipped by the sync, which still applies the complete rules', function () {
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([
            leadsRule(),
            new SegmentRuleData('rule-2', 'Huge', [
                SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::MoreThanDaysAgo, ['days' => 999999999]),
            ]),
        ])->create();

        expect(fn () => SyncSegmentMembership::dispatchSync($segment->id))->not->toThrow(Throwable::class);

        expect($segment->contacts()->pluck('contacts.id')->all())->toBe([$lead->id]);
    });
});

describe('rules version', function () {
    it('goes up when new rules are published', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();
        $segment->storeWorkingRules([partnersRule()]);

        expect($segment->fresh()->rules_version)->toBe(1);

        $segment->fresh()->publishWorkingRules();

        expect($segment->fresh()->rules_version)->toBe(2);
    });

    it('does not change for drafts, renames or sync flags', function (Closure $change) {
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();

        $change($segment->fresh());

        expect($segment->fresh()->rules_version)->toBe(1);
    })->with([
        'a draft rule' => [fn (Segment $segment) => $segment->storeWorkingRules([leadsRule(), partnersRule()])],
        'a rename' => [fn (Segment $segment) => $segment->update(['name' => 'Renamed'])],
        'the sync flags' => [fn (Segment $segment) => $segment->update(['is_syncing' => true, 'last_synced_at' => now()])],
    ]);

    it('starts at one for a new segment', function () {
        expect(Segment::factory()->for($this->org)->create()->fresh()->rules_version)->toBe(1);
    });
});

describe('lock window', function () {
    it('computes the members before it locks the segment row and removes stale ones with NOT EXISTS', function () {
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();

        DB::enableQueryLog();
        SyncSegmentMembership::dispatchSync($segment->id);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query))->values();

        $computedAt = $queries->search(fn (string $query): bool => str_starts_with($query, 'insert into segment_sync_matches'));
        $lockedAt = $queries->search(fn (string $query): bool => str_contains($query, 'from "segments"') && str_contains($query, 'for update'));
        $removal = $queries->first(fn (string $query): bool => str_starts_with($query, 'delete from contact_segment'));

        expect($computedAt)->not->toBeFalse()
            ->and($lockedAt)->not->toBeFalse()
            ->and($computedAt)->toBeLessThan($lockedAt)
            ->and($removal)->toContain('not exists')
            ->and($removal)->not->toContain('not in');
    });

    it('gives the same members as before and keeps the joined at dates', function () {
        $alice = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $bob = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        $carol = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['is_syncing' => true]);
        $segment->contacts()->attach($alice, ['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
        $segment->contacts()->attach($bob);

        SyncSegmentMembership::dispatchSync($segment->id);

        $joinedAt = DB::table('contact_segment')->where('segment_id', $segment->id)->where('contact_id', $alice->id)->value('created_at');

        expect(membersOfSegment($segment))->toBe(collect([$alice->id, $carol->id])->sort()->values()->all())
            ->and(now()->parse($joinedAt)->isSameDay(now()->subDays(30)))->toBeTrue()
            ->and($segment->fresh()->is_syncing)->toBeFalse()
            ->and($segment->fresh()->last_synced_at)->not->toBeNull();
    });

    it('discards the result and queues a new sync when the rules are published during the compute phase', function () {
        Queue::fake([SyncSegmentMembership::class]);
        $user = User::factory()->create();
        $alice = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $bob = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['is_syncing' => true]);
        $segment->contacts()->attach($bob);
        $bumped = false;
        DB::listen(function (QueryExecuted $query) use ($segment, &$bumped): void {
            if (! $bumped && str_starts_with(strtolower($query->sql), 'insert into segment_sync_matches')) {
                $bumped = true;
                DB::table('segments')->where('id', $segment->id)->update(['rules_version' => 2]);
            }
        });

        (new SyncSegmentMembership($segment->id, $user->id))->handle();

        expect($bumped)->toBeTrue()
            ->and(membersOfSegment($segment))->toBe([$bob->id])
            ->and($segment->fresh()->is_syncing)->toBeTrue()
            ->and($user->notifications()->count())->toBe(0);
        Queue::assertPushed(SyncSegmentMembership::class, 1);
        Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $segment->id && $job->notifyUserId === $user->id);
        expect(DB::table('contact_segment')->where('contact_id', $alice->id)->count())->toBe(0);
    });

    it('applies the result when only the draft changes during the compute phase', function () {
        $alice = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['is_syncing' => true]);
        $drafted = false;
        DB::listen(function (QueryExecuted $query) use ($segment, &$drafted): void {
            if (! $drafted && str_starts_with(strtolower($query->sql), 'insert into segment_sync_matches')) {
                $drafted = true;
                DB::table('segments')->where('id', $segment->id)->update(['draft_rules' => json_encode([partnersRule()->toArray()])]);
            }
        });

        SyncSegmentMembership::dispatchSync($segment->id);

        expect($drafted)->toBeTrue()
            ->and(membersOfSegment($segment))->toBe([$alice->id])
            ->and($segment->fresh()->is_syncing)->toBeFalse()
            ->and($segment->fresh()->draft_rules)->not->toBeNull();
    });

    it('can run twice in a row in the same connection', function () {
        $alice = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create();

        SyncSegmentMembership::dispatchSync($segment->id);
        SyncSegmentMembership::dispatchSync($segment->id);

        expect(membersOfSegment($segment))->toBe([$alice->id]);
    });
});

describe('per-contact resync and rule changes', function () {
    beforeEach(function () {
        Queue::fake([ResyncContactSegments::class]);
        $this->leads = Segment::factory()->for($this->org)->published()->withRules([leadsRule()])->create(['name' => 'Leads']);
        $this->partners = Segment::factory()->for($this->org)->published()->withRules([partnersRule()])->create(['name' => 'Partners']);
        $this->jane = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $this->leads->contacts()->attach($this->jane);
        $this->jane->update(['status' => ContactStatus::Partner]);
    });

    /**
     * Runs the callback right after the resync evaluated the contact, the way a publish landing between the
     * evaluation and the write would, on the same connection.
     */
    function afterTheEvaluation(Closure $callback): void
    {
        $done = false;

        DB::listen(function (QueryExecuted $query) use ($callback, &$done): void {
            if (! $done && str_contains($query->sql, 'AS "segment_')) {
                $done = true;
                $callback();
            }
        });
    }

    it('applies the evaluation when nothing changed', function () {
        resyncContact($this->jane);

        expect(membersOfSegment($this->partners))->toBe([$this->jane->id])
            ->and(membersOfSegment($this->leads))->toBe([]);
    });

    it('leaves a segment alone when its rules were republished during the resync', function () {
        afterTheEvaluation(fn () => DB::table('segments')->where('id', $this->partners->id)->increment('rules_version'));

        resyncContact($this->jane);

        expect(membersOfSegment($this->partners))->toBe([])
            ->and(membersOfSegment($this->leads))->toBe([]);
    });

    it('leaves a segment alone when it was unpublished during the resync', function () {
        afterTheEvaluation(fn () => DB::table('segments')->where('id', $this->leads->id)->update(['is_published' => false]));

        resyncContact($this->jane);

        expect(membersOfSegment($this->leads))->toBe([$this->jane->id])
            ->and(membersOfSegment($this->partners))->toBe([$this->jane->id]);
    });

    it('writes nothing when every segment changed', function () {
        afterTheEvaluation(function (): void {
            DB::table('segments')->whereIn('id', [$this->leads->id, $this->partners->id])->increment('rules_version');
        });

        resyncContact($this->jane);

        expect(membersOfSegment($this->leads))->toBe([$this->jane->id])
            ->and(membersOfSegment($this->partners))->toBe([]);
    });

    it('does not count a draft edit as a change', function () {
        afterTheEvaluation(fn () => DB::table('segments')->where('id', $this->partners->id)->update(['draft_rules' => json_encode([leadsRule()->toArray()])]));

        resyncContact($this->jane);

        expect(membersOfSegment($this->partners))->toBe([$this->jane->id]);
    });

    it('checks the versions under a share lock before writing the memberships', function () {
        DB::enableQueryLog();

        resyncContact($this->jane);

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query))->values();
        $lockedAt = $queries->search(fn (string $query): bool => str_contains($query, 'from "segments"') && str_contains($query, 'for share'));
        $writtenAt = $queries->search(fn (string $query): bool => str_contains($query, 'contact_segment') && (str_starts_with($query, 'insert') || str_starts_with($query, 'delete')));

        expect($lockedAt)->not->toBeFalse()
            ->and($writtenAt)->not->toBeFalse()
            ->and($lockedAt)->toBeLessThan($writtenAt);
    });
});
