<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Segments\SegmentResource;
use App\Filament\Resources\Segments\Widgets\SegmentStatsOverview;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function publishableRules(): array
{
    return [new SegmentRuleData('rule-1', 'Leads', [
        SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
    ])];
}

describe('list page', function () {
    it('renders the segments of the organization only', function () {
        $segments = Segment::factory()->for($this->org)->count(2)->create();
        $otherSegment = Segment::factory()->create();

        Livewire::test(ListSegments::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($segments)
            ->assertCanNotSeeTableRecords([$otherSegment])
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('contacts_count');
    });

    it('creates a segment and redirects to its rules', function () {
        Livewire::test(ListSegments::class)
            ->callAction('create', ['name' => 'Newsletter'])
            ->assertHasNoActionErrors()
            ->assertRedirect(SegmentResource::getUrl('rules', ['record' => Segment::query()->firstWhere('name', 'Newsletter')]));

        $this->assertDatabaseHas(Segment::class, ['name' => 'Newsletter', 'organization_id' => $this->org->id, 'is_published' => false]);
    });

    it('requires a unique name within the organization', function () {
        Segment::factory()->for($this->org)->create(['name' => 'Newsletter']);

        Livewire::test(ListSegments::class)
            ->callAction('create', ['name' => 'Newsletter'])
            ->assertHasActionErrors(['name' => 'unique']);
    });

    it('soft deletes a segment and keeps its members', function () {
        $segment = Segment::factory()->for($this->org)->published()->create();
        $member = Contact::factory()->for($this->org)->create();
        $segment->contacts()->attach($member);

        Livewire::test(ListSegments::class)
            ->callAction(TestAction::make('delete')->table($segment))
            ->assertCanNotSeeTableRecords([$segment]);

        $this->assertSoftDeleted($segment);
        expect($segment->contacts()->count())->toBe(1);
    });

    it('lists trashed segments and restores them, recomputing their members', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules(publishableRules())->create();
        $lead = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        $segment->delete();

        Livewire::test(ListSegments::class)
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$segment])
            ->assertTableActionHidden('editRules', $segment)
            ->callAction(TestAction::make('restore')->table($segment));

        $this->assertNotSoftDeleted($segment);
        expect($segment->fresh()->is_syncing)->toBeFalse()
            ->and($segment->contacts()->pluck('contacts.id')->all())->toBe([$lead->id]);
    });

    it('explains that a trashed segment may hold the requested name', function () {
        Segment::factory()->for($this->org)->create(['name' => 'Newsletter'])->delete();

        Livewire::test(ListSegments::class)
            ->callAction('create', ['name' => 'Newsletter'])
            ->assertHasActionErrors(['name' => 'unique'])
            ->assertMountedActionModalSee('check the "Trashed" filter of the list and restore it');
    });

    it('has no force delete actions', function () {
        $segment = Segment::factory()->for($this->org)->create();
        $segment->delete();

        Livewire::test(ListSegments::class)
            ->filterTable('trashed', true)
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('forceDelete');

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->assertActionDoesNotExist('forceDelete')
            ->assertActionVisible('restore')
            ->assertActionHidden('publish')
            ->assertActionHidden('editRules');
    });
});

describe('view page', function () {
    it('renders with its stats and members', function () {
        $segment = Segment::factory()->for($this->org)->published()->withRules(publishableRules())->create();
        $member = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);
        SyncSegmentMembership::dispatchSync($segment->id);

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Leads');

        Livewire::test(SegmentStatsOverview::class, ['record' => $segment])
            ->assertSuccessful()
            ->assertSee('Members');

        Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $segment, 'pageClass' => ViewSegment::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$member]);
    });

    it('publishes the segment', function () {
        Queue::fake();
        $segment = Segment::factory()->for($this->org)->withRules(publishableRules())->create();

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->callAction('publish')
            ->assertRedirect(SegmentResource::getUrl('view', ['record' => $segment]));

        expect($segment->fresh())
            ->is_published->toBeTrue()
            ->is_syncing->toBeTrue();

        Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $segment->id
            && $job->notifyUserId === $this->user->id);
    });

    it('cannot publish a segment without complete rules', function () {
        $segment = Segment::factory()->for($this->org)->create();

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->assertActionDisabled('publish');
    });

    it('reloads once the members are computed', function () {
        $segment = Segment::factory()->for($this->org)->published()->create(['is_syncing' => true]);

        $page = Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->call('checkSyncStatus')
            ->assertNoRedirect();

        $segment->update(['is_syncing' => false]);

        $page->call('checkSyncStatus')
            ->assertNotified('Segment setup complete')
            ->assertRedirect(SegmentResource::getUrl('view', ['record' => $segment]));
    });

    it('cannot be accessed from another organization', function () {
        $segment = Segment::factory()->create();

        $this->get(SegmentResource::getUrl('view', ['record' => $segment]))->assertNotFound();
    });
});
