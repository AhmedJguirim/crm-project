<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
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

    it('permanently deletes a segment and its member list, keeping the contacts', function () {
        $segment = Segment::factory()->for($this->org)->published()->create();
        $member = Contact::factory()->for($this->org)->create();
        $segment->contacts()->attach($member);

        Livewire::test(ListSegments::class)
            ->assertTableActionDoesNotExist('restore')
            ->callAction(TestAction::make('delete')->table($segment))
            ->assertCanNotSeeTableRecords([$segment]);

        $this->assertModelMissing($segment);
        $this->assertDatabaseMissing('contact_segment', ['segment_id' => $segment->id]);
        $this->assertModelExists($member);
    });

    it('permanently deletes segments in bulk', function () {
        $segments = Segment::factory()->for($this->org)->count(2)->create();

        Livewire::test(ListSegments::class)
            ->selectTableRecords($segments)
            ->callAction(TestAction::make('delete')->table()->bulk());

        $segments->each(fn (Segment $segment) => $this->assertModelMissing($segment));
    });

    it('warns that deleting a segment cannot be undone', function () {
        $segment = Segment::factory()->for($this->org)->create(['name' => 'Newsletter']);

        Livewire::test(ListSegments::class)
            ->mountAction(TestAction::make('delete')->table($segment))
            ->assertMountedActionModalSee('This cannot be undone.');
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

it('permanently deletes a segment from its view and rules pages', function (string $page) {
    $segment = Segment::factory()->for($this->org)->create();

    Livewire::test($page, ['record' => $segment->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(SegmentResource::getUrl('index'));

    $this->assertModelMissing($segment);
})->with([
    'view page' => ViewSegment::class,
    'rules page' => SegmentRuleEngine::class,
]);
