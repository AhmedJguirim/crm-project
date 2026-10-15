<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Martin']);
    $this->org = $this->alice->personalOrganization();
    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd']);
    $this->zeta = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zeta Corp']);
    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);

    $this->makeDeal = fn (string $title, ?Company $company, string $stage, ?string $closeDate, ?string $value, ?Contact $contact = null): Deal => Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'owner_id' => $this->alice->id,
        'company_id' => $company?->id,
        'contact_id' => $contact?->id,
        'title' => $title,
        'expected_close_date' => $closeDate,
        'value' => $value,
        ...DealStageMover::attributesFor(pipelineStageNamed($this->org->id, $stage)),
    ]);

    $this->late = ($this->makeDeal)('Late renewal', $this->acme, 'Proposal Sent', '2026-10-05', '1200.00', $this->jane);
    $this->next = ($this->makeDeal)('Next phase', $this->acme, 'Lead', '2026-11-20', null);
    $this->undated = ($this->makeDeal)('Undated idea', $this->acme, 'Lead', null, '300.00');
    $this->old = ($this->makeDeal)('Old pilot', $this->acme, 'Lost', '2026-10-01', '500.00');
    $this->zetaDeal = ($this->makeDeal)('Zeta audit', $this->zeta, 'Lead', '2026-10-20', '900.00');
    $this->orphan = ($this->makeDeal)('No company deal', null, 'Lead', '2026-10-20', '100.00');

    $this->manager = fn (?Company $company = null) => Livewire::test(DealsRelationManager::class, [
        'ownerRecord' => $company ?? $this->acme,
        'pageClass' => EditCompany::class,
    ]);
});

test('Company::deals() returns only the deals linked to the company', function () {
    expect($this->acme->deals->pluck('title')->sort()->values()->all())->toBe(['Late renewal', 'Next phase', 'Old pilot', 'Undated idea'])
        ->and($this->zeta->deals->pluck('title')->all())->toBe(['Zeta audit']);
});

test('the relation manager lists the deals of the company, open first, then by close date, empty dates last', function () {
    ($this->manager)()
        ->assertOk()
        ->assertCanSeeTableRecords([$this->late, $this->next, $this->undated, $this->old], inOrder: true)
        ->assertCanNotSeeTableRecords([$this->zetaDeal, $this->orphan]);
});

test('a deal of another organization never shows, even when it points at the company id', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $leak = Deal::factory()->create([
        'organization_id' => $globex->personalOrganization()->id,
        'created_by' => $globex->id,
        'company_id' => $this->acme->id,
        'title' => 'Leak',
    ]);

    ($this->manager)()->assertCanNotSeeTableRecords([$leak]);

    expect(DealsRelationManager::getBadge($this->acme, EditCompany::class))->toBe('4');
});

test('the columns show the deal facts', function () {
    ($this->manager)()
        ->assertTableColumnStateSet('value', 1200, $this->late)
        ->assertTableColumnStateSet('contact.name', 'Jane Doe', $this->late)
        ->assertTableColumnStateSet('owner_id', 'Alice Martin', $this->late)
        ->assertSee('$1,200.00');

});

test('an open deal past its close date is red, a later one is not', function () {
    $table = ($this->manager)()->instance()->getTable();
    $column = $table->getColumn('expected_close_date');

    expect($column->record($this->late)->getColor(null))->toBe('danger')
        ->and($column->record($this->next)->getColor(null))->toBeNull();
});

test('a row opens the deal page', function () {
    $table = ($this->manager)()->instance()->getTable();

    expect($table->getRecordUrl($this->late))->toBe(DealResource::getUrl('view', ['record' => $this->late]));
});

test('the table does not query per row for the contact and the owner', function () {
    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        ($this->manager)()->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $before = $count();

    foreach (range(1, 4) as $index) {
        $owner = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($owner, ['role' => OrganizationRole::Member->value]);
        Deal::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->alice->id,
            'owner_id' => $owner->id,
            'company_id' => $this->acme->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
            'title' => 'Extra '.$index,
        ]);
    }

    expect($count())->toBe($before);
});

test('the table can be filtered on the status', function () {
    ($this->manager)()
        ->filterTable('status', DealStatus::Lost->value)
        ->assertCanSeeTableRecords([$this->old])
        ->assertCanNotSeeTableRecords([$this->late, $this->next, $this->undated]);
});

test('the badge counts the deals and is empty without any', function () {
    $empty = Company::factory()->create(['organization_id' => $this->org->id]);

    expect(DealsRelationManager::getBadge($this->acme, EditCompany::class))->toBe('4')
        ->and(DealsRelationManager::getBadge($empty, EditCompany::class))->toBeNull();
});

test('the edit page offers the Deals tab after Contacts', function () {
    expect(CompanyResource::getRelations())->toBe([ContactsRelationManager::class, DealsRelationManager::class]);

    $page = Livewire::test(EditCompany::class, ['record' => $this->acme->id])
        ->assertSeeLivewire(ContactsRelationManager::class)
        ->set('activeRelationManager', '1');

    $name = app('livewire.factory')->resolveComponentName(DealsRelationManager::class);

    expect($page->html())->toContain(trim(htmlspecialchars(json_encode(['name' => $name])), '{}'));
});

test('a deleted company still lists its deals', function () {
    $this->acme->delete();

    ($this->manager)()->assertCanSeeTableRecords([$this->late]);
});

test('the tab has no create, edit, associate, dissociate or delete action', function () {
    $table = ($this->manager)()->instance()->getTable();

    expect($table->getHeaderActions())->toBeEmpty()
        ->and($table->getFlatRecordActions())->toBeEmpty()
        ->and($table->getFlatBulkActions())->toBeEmpty();
});

test('the empty state explains where deals come from', function () {
    $empty = Company::factory()->create(['organization_id' => $this->org->id]);

    ($this->manager)($empty)
        ->assertSee('No deals for this company')
        ->assertSee('Deals linked to this company in the deal form appear here.');
});

test('every member of the organization who can open the company sees the tab', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);

    ($this->manager)()->assertOk()->assertCanSeeTableRecords([$this->late]);
})->with([OrganizationRole::Admin, OrganizationRole::Member]);
