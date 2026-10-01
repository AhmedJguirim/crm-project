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
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function leadsRule(): SegmentRuleData
{
    return new SegmentRuleData('rule-1', 'Leads', [
        SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
    ]);
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
