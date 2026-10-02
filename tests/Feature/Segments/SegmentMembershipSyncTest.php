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
use Illuminate\Support\Collection;
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
