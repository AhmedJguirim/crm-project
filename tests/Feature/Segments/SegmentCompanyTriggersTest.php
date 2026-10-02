<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function companyConditionSegment(Organization $organization, SegmentOperator $operator, array $values = [], bool $published = true): Segment
{
    $condition = SegmentConditionData::make(SegmentConditionType::Company, null, $operator, $values === [] ? [] : ['values' => $values]);

    return Segment::factory()->for($organization)->state(['is_published' => $published])->withRules([
        new SegmentRuleData('rule-1', 'Company', [$condition]),
    ])->create();
}

function syncSegmentsNow(Segment ...$segments): void
{
    foreach ($segments as $segment) {
        SyncSegmentMembership::dispatchSync($segment->id);
    }
}

function segmentMemberIds(Segment $segment): array
{
    return $segment->contacts()->pluck('contacts.id')->sort()->values()->all();
}

function companyContactsTab(Company $company): Testable
{
    return Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $company,
        'pageClass' => EditCompany::class,
    ]);
}

describe('company links edited from the company contacts tab', function () {
    beforeEach(function () {
        $this->partner = CompanyType::factory()->create(['organization_id' => $this->org->id]);
        $this->acme = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->partner->id]);
        $this->partnerContacts = companyConditionSegment($this->org, SegmentOperator::CompanyTypeIsAnyOf, [$this->partner->id]);
        $this->hasCompany = companyConditionSegment($this->org, SegmentOperator::HasAnyCompany);
        $this->jane = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->john = Contact::factory()->create(['organization_id' => $this->org->id]);
    });

    it('adds an attached contact to the matching segments', function () {
        companyContactsTab($this->acme)
            ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$this->jane->id]])
            ->assertHasNoActionErrors();

        expect(segmentMemberIds($this->partnerContacts))->toBe([$this->jane->id])
            ->and(segmentMemberIds($this->hasCompany))->toBe([$this->jane->id]);
    });

    it('removes a detached contact from the segments', function () {
        $this->acme->contacts()->attach($this->jane);
        syncSegmentsNow($this->partnerContacts, $this->hasCompany);

        companyContactsTab($this->acme)->callAction(TestAction::make(DetachAction::class)->table($this->jane));

        expect(segmentMemberIds($this->partnerContacts))->toBe([])
            ->and(segmentMemberIds($this->hasCompany))->toBe([]);
    });

    it('removes bulk detached contacts from the segments', function () {
        $this->acme->contacts()->attach([$this->jane->id, $this->john->id]);
        syncSegmentsNow($this->partnerContacts, $this->hasCompany);

        companyContactsTab($this->acme)
            ->selectTableRecords([$this->jane->id, $this->john->id])
            ->callAction(TestAction::make(DetachBulkAction::class)->table()->bulk());

        expect(segmentMemberIds($this->partnerContacts))->toBe([])
            ->and(segmentMemberIds($this->hasCompany))->toBe([]);
    });

    it('only resyncs the contacts that were touched', function () {
        Queue::fake([ResyncContactSegments::class, SyncSegmentMembership::class]);

        companyContactsTab($this->acme)
            ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$this->jane->id, $this->john->id]]);

        expect(Queue::pushed(ResyncContactSegments::class)->map->contactId->sort()->values()->all())
            ->toBe([$this->jane->id, $this->john->id]);
        Queue::assertNotPushed(SyncSegmentMembership::class);
    });
});

describe('company type changes', function () {
    beforeEach(function () {
        $this->partner = CompanyType::factory()->create(['organization_id' => $this->org->id]);
        $this->supplier = CompanyType::factory()->create(['organization_id' => $this->org->id]);
        $this->acme = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->supplier->id]);
        $this->jane = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->acme->contacts()->attach($this->jane);
        $this->partnerContacts = companyConditionSegment($this->org, SegmentOperator::CompanyTypeIsAnyOf, [$this->partner->id]);
        syncSegmentsNow($this->partnerContacts);
    });

    it('moves the contacts in when the company becomes of the type', function () {
        Livewire::test(EditCompany::class, ['record' => $this->acme->id])
            ->fillForm(['company_type_id' => $this->partner->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(segmentMemberIds($this->partnerContacts))->toBe([$this->jane->id]);
    });

    it('moves the contacts out when the company stops being of the type', function () {
        $this->acme->update(['company_type_id' => $this->partner->id]);
        expect(segmentMemberIds($this->partnerContacts))->toBe([$this->jane->id]);

        Livewire::test(EditCompany::class, ['record' => $this->acme->id])
            ->fillForm(['company_type_id' => $this->supplier->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(segmentMemberIds($this->partnerContacts))->toBe([]);
    });

    it('queues nothing for other changes', function () {
        Queue::fake([SyncSegmentMembership::class]);

        $this->acme->update(['name' => 'Renamed']);

        Queue::assertNotPushed(SyncSegmentMembership::class);
    });
});

describe('company soft-delete and restore', function () {
    beforeEach(function () {
        $this->acme = Company::factory()->create(['organization_id' => $this->org->id]);
        $this->jane = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->acme->contacts()->attach($this->jane);
        $this->hasCompany = companyConditionSegment($this->org, SegmentOperator::HasAnyCompany);
        $this->noCompany = companyConditionSegment($this->org, SegmentOperator::HasNoCompany);
        syncSegmentsNow($this->hasCompany, $this->noCompany);
    });

    it('updates the segments when a company is deleted', function (string $how) {
        match ($how) {
            'from its edit page' => Livewire::test(EditCompany::class, ['record' => $this->acme->id])->callAction(DeleteAction::class),
            'from the companies table' => Livewire::test(ListCompanies::class)->callAction(TestAction::make('delete')->table($this->acme)),
            'with the companies table bulk action' => Livewire::test(ListCompanies::class)
                ->selectTableRecords([$this->acme->id])
                ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk()),
        };

        expect($this->acme->fresh()->trashed())->toBeTrue()
            ->and(segmentMemberIds($this->hasCompany))->toBe([])
            ->and(segmentMemberIds($this->noCompany))->toBe([$this->jane->id]);
    })->with(['from its edit page', 'from the companies table', 'with the companies table bulk action']);

    it('puts the contacts back when the company is restored', function () {
        $this->acme->delete();
        expect(segmentMemberIds($this->noCompany))->toBe([$this->jane->id]);

        Livewire::test(EditCompany::class, ['record' => $this->acme->id])->callAction(RestoreAction::class);

        expect(segmentMemberIds($this->hasCompany))->toBe([$this->jane->id])
            ->and(segmentMemberIds($this->noCompany))->toBe([]);
    });
});

describe('segments synced by a company change', function () {
    it('only queues the published segments with company conditions of the organization', function () {
        $companySegment = companyConditionSegment($this->org, SegmentOperator::HasAnyCompany);
        companyConditionSegment($this->org, SegmentOperator::HasAnyCompany, published: false);
        Segment::factory()->for($this->org)->published()->withRules([
            new SegmentRuleData('rule-1', 'Tagged', [
                SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [Tag::factory()->for($this->org)->create()->id]]),
            ]),
        ])->create();
        companyConditionSegment(Organization::factory()->create(), SegmentOperator::HasAnyCompany);
        $company = Company::factory()->create(['organization_id' => $this->org->id]);
        Queue::fake([SyncSegmentMembership::class]);

        $company->delete();

        Queue::assertPushed(SyncSegmentMembership::class, 1);
        Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $companySegment->id);
    });

    it('does not queue anything when a company type is deleted and restored', function () {
        companyConditionSegment($this->org, SegmentOperator::HasAnyCompany);
        $type = CompanyType::factory()->create(['organization_id' => $this->org->id]);
        Queue::fake([SyncSegmentMembership::class]);

        $type->delete();
        $type->restore();

        Queue::assertNotPushed(SyncSegmentMembership::class);
    });
});
