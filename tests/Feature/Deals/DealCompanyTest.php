<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\DealCompany;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->alice->personalOrganization();
    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd']);
    $this->zeta = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zeta Corp']);

    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->jane->companies()->attach($this->acme);
    $this->john = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'John Roe']);
    $this->john->companies()->attach([$this->zeta->id, $this->acme->id]);
    $this->sam = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Solo Sam']);

    $this->globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->otherOrg = $this->globex->personalOrganization();

    $this->makeDeal = fn (string $title, ?Company $company = null, ?Contact $contact = null): Deal => Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'contact_id' => $contact?->id,
        'company_id' => $company?->id,
        'title' => $title,
    ]);
});

test('the default company of a contact is its only live company', function (string $contact, string $result) {
    $id = match ($contact) {
        'jane' => $this->jane->id,
        'jane string' => (string) $this->jane->id,
        'john' => $this->john->id,
        'sam' => $this->sam->id,
        'null' => null,
        'blank' => '',
        'letters' => 'abc',
        'trashed contact' => (function () {
            $this->jane->delete();

            return $this->jane->id;
        })(),
        'trashed company' => (function () {
            $this->acme->delete();

            return $this->jane->id;
        })(),
        'other organization' => Contact::factory()->create(['organization_id' => $this->otherOrg->id])->id,
    };

    $expected = $result === 'acme' ? $this->acme->id : null;

    expect(DealCompany::defaultIdFor($id))->toBe($expected);
})->with([
    'single company' => ['jane', 'acme'],
    'single company, id as a string' => ['jane string', 'acme'],
    'two companies' => ['john', 'none'],
    'no company' => ['sam', 'none'],
    'null' => ['null', 'none'],
    'blank string' => ['blank', 'none'],
    'letters' => ['letters', 'none'],
    'trashed contact' => ['trashed contact', 'none'],
    'trashed company' => ['trashed company', 'none'],
    'contact of another organization' => ['other organization', 'none'],
]);

test('a contact whose single company belongs to another organization gets no default', function () {
    $foreign = Company::factory()->create(['organization_id' => $this->otherOrg->id]);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $contact->companies()->attach($foreign);

    expect(DealCompany::defaultIdFor($contact->id))->toBeNull();
});

test('the default company is found for the right organization without a Filament tenant', function () {
    $contactId = $this->jane->id;
    Filament::setTenant(null);

    $found = app(TenantContext::class)->run($this->org->id, fn (): ?int => DealCompany::defaultIdFor($contactId));
    $other = app(TenantContext::class)->run($this->otherOrg->id, fn (): ?int => DealCompany::defaultIdFor($contactId));

    expect($found)->toBe($this->acme->id)->and($other)->toBeNull();
});

test('choosing a contact fills an empty company and the deal is saved with it', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['contact_id' => $this->jane->id])
        ->assertSchemaStateSet(['company_id' => $this->acme->id])
        ->fillForm(['title' => 'Retainer'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Deal::query()->where('title', 'Retainer')->firstOrFail()->company_id)->toBe($this->acme->id);
});

test('a company chosen by the user is never overwritten by the contact', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['company_id' => $this->zeta->id])
        ->fillForm(['contact_id' => $this->jane->id])
        ->assertSchemaStateSet(['company_id' => $this->zeta->id]);
});

test('clearing the contact keeps the company', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['contact_id' => $this->jane->id])
        ->fillForm(['contact_id' => null])
        ->assertSchemaStateSet(['company_id' => $this->acme->id]);
});

test('a contact with two companies or none leaves the company empty', function (string $contact) {
    Livewire::test(CreateDeal::class)
        ->fillForm(['contact_id' => $this->{$contact}->id])
        ->assertSchemaStateSet(['company_id' => null]);
})->with(['john', 'sam']);

test('a company of another organization is refused', function () {
    $spy = Company::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Spy Inc']);

    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Forged', 'company_id' => $spy->id])
        ->call('create')
        ->assertHasFormErrors(['company_id']);

    expect(Deal::query()->where('title', 'Forged')->exists())->toBeFalse();
});

test('a forged company id that does not exist or is not a number is refused and the form still renders', function (mixed $value) {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Forged', 'company_id' => $value])
        ->call('create')
        ->assertHasFormErrors(['company_id']);

    expect(Deal::query()->where('title', 'Forged')->exists())->toBeFalse();
})->with(['unknown id' => 99999999, 'letters' => 'abc']);

test('a deleted company stays on the deal on an unrelated edit, with a deleted label', function () {
    $deal = ($this->makeDeal)('Retainer', $this->acme);
    $this->acme->delete();

    $page = Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['title' => 'Retainer 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->fresh())->title->toBe('Retainer 2')->company_id->toBe($this->acme->id);

    $select = $page->instance()->getSchema('form')->getComponent('company_id');

    expect($select->getOptionLabel())->toBe('Acme Ltd (deleted)')
        ->and($select->getSearchResults('Acme'))->toBe([]);
});

test('a deleted company cannot be picked on another deal', function () {
    $this->acme->delete();
    $other = ($this->makeDeal)('Other');

    Livewire::test(EditDeal::class, ['record' => $other->id])
        ->fillForm(['company_id' => $this->acme->id])
        ->call('save')
        ->assertHasFormErrors(['company_id']);

    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'New', 'company_id' => $this->acme->id])
        ->call('create')
        ->assertHasFormErrors(['company_id']);

    expect($other->fresh()->company_id)->toBeNull();
});

test('the company can be changed and removed on a deal', function () {
    $deal = ($this->makeDeal)('Retainer', $this->acme);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['company_id' => $this->zeta->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->fresh()->company_id)->toBe($this->zeta->id);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['company_id' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->fresh()->company_id)->toBeNull();
});

test('deleting a company keeps its id on the deal', function () {
    $deal = ($this->makeDeal)('Retainer', $this->acme);

    $this->acme->delete();

    expect($deal->fresh()->company_id)->toBe($this->acme->id)
        ->and($deal->fresh()->company)->toBeNull()
        ->and($deal->fresh()->companyLabel())->toBe('Acme Ltd (deleted)');
});

dataset('company inline deal forms', [
    'view contact log activity' => [
        fn (Contact $contact) => Livewire::test(ViewContact::class, ['record' => $contact->id]),
        fn (Contact $contact): array => [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'contacts table log activity' => [
        fn (Contact $contact) => Livewire::test(ListContacts::class),
        fn (Contact $contact): array => [TestAction::make('logActivity')->table($contact), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'quick task on a contact' => [
        fn (Contact $contact) => Livewire::test(ViewContact::class, ['record' => $contact->id])->mountAction('quickTask'),
        fn (Contact $contact): TestAction => TestAction::make('createOption')->schemaComponent('deal_id'),
    ],
    'create task page' => [
        fn (Contact $contact) => Livewire::test(CreateTask::class)->fillForm(['contact_id' => $contact->id]),
        fn (Contact $contact): TestAction => TestAction::make('createOption')->schemaComponent('deal_id'),
    ],
]);

test('an inline deal create takes the single company of its contact and leaves it empty for an ambiguous contact', function (Closure $page, Closure $actions) {
    $page($this->jane)->callAction($actions($this->jane), data: ['title' => 'Inline'])->assertHasNoActionErrors();
    $page($this->john)->callAction($actions($this->john), data: ['title' => 'Inline 2'])->assertHasNoActionErrors();

    expect(Deal::query()->where('title', 'Inline')->firstOrFail()->company_id)->toBe($this->acme->id)
        ->and(Deal::query()->where('title', 'Inline 2')->firstOrFail()->company_id)->toBeNull();
})->with('company inline deal forms');

test('an inline deal created from a deal log activity takes the single company of the deal contact', function () {
    $deal = ($this->makeDeal)('Source', null, $this->jane);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction(
            [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
            data: ['title' => 'Inline'],
        )
        ->assertHasNoActionErrors();

    expect(Deal::query()->where('title', 'Inline')->firstOrFail()->company_id)->toBe($this->acme->id);
});

test('the deals table shows the company, links it and filters by it', function () {
    $a = ($this->makeDeal)('A', $this->acme);
    $b = ($this->makeDeal)('B');
    $old = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Old Co']);
    $c = ($this->makeDeal)('C', $old);
    $old->delete();

    $page = Livewire::test(ListDeals::class)
        ->assertTableColumnStateSet('company_id', 'Acme Ltd', $a)
        ->assertTableColumnStateSet('company_id', null, $b)
        ->assertTableColumnStateSet('company_id', 'Old Co (deleted)', $c)
        ->assertSee('No company');

    $column = $page->instance()->getTable()->getColumn('company_id');

    expect($column->record($a)->getUrl())->toBe(CompanyResource::getUrl('edit', ['record' => $this->acme]))
        ->and($column->record($b)->getUrl())->toBeNull()
        ->and($column->record($c)->getUrl())->toBe(CompanyResource::getUrl('edit', ['record' => $old]));

    $page->filterTable('company_id', $this->acme->id)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b, $c]);
});

test('the deals table does not query a company per row', function () {
    foreach (range(1, 5) as $i) {
        ($this->makeDeal)("Deal {$i}", Company::factory()->create(['organization_id' => $this->org->id]));
    }

    $count = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "companies"')) {
                $queries++;
            }
        });

        Livewire::test(ListDeals::class)->assertSuccessful();

        return $queries;
    };

    $five = $count();

    foreach (range(6, 12) as $i) {
        ($this->makeDeal)("Deal {$i}", Company::factory()->create(['organization_id' => $this->org->id]));
    }

    expect($count())->toBe($five);
});

test('the deal page shows the company or none', function () {
    $a = ($this->makeDeal)('A', $this->acme);
    $b = ($this->makeDeal)('B');

    Livewire::test(DealDetailsWidget::class, ['record' => $a])
        ->assertSee('Company')
        ->assertSee('Acme Ltd')
        ->assertSeeHtml('href="'.CompanyResource::getUrl('edit', ['record' => $this->acme]).'"');

    Livewire::test(DealDetailsWidget::class, ['record' => $b])->assertSee('No company');

    $this->acme->delete();

    Livewire::test(DealDetailsWidget::class, ['record' => $a->fresh()])->assertSee('Acme Ltd (deleted)');
});

test('html in a company name is escaped in the table and on the deal page', function () {
    $evil = Company::factory()->create(['organization_id' => $this->org->id, 'name' => '<b>Evil</b>']);
    $deal = ($this->makeDeal)('Evil deal', $evil);

    Livewire::test(ListDeals::class)->assertDontSeeHtml('<b>Evil</b>');
    Livewire::test(DealDetailsWidget::class, ['record' => $deal])->assertDontSeeHtml('<b>Evil</b>');
});

test("another organization's company is never shown on a deal of this organization", function () {
    $spy = Company::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Spy Inc']);
    $deal = ($this->makeDeal)('Forged row');
    $deal->forceFill(['company_id' => $spy->id])->saveQuietly();

    Livewire::test(ListDeals::class)->assertDontSee('Spy Inc');
    Livewire::test(DealDetailsWidget::class, ['record' => $deal])->assertDontSee('Spy Inc');
    Livewire::test(DealPipeline::class)->assertDontSee('Spy Inc');
});

test('every role sees the company of a deal', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);
    Filament::setTenant($this->org);
    $deal = ($this->makeDeal)('A', $this->acme);

    Livewire::test(ListDeals::class)->assertTableColumnStateSet('company_id', 'Acme Ltd', $deal);
})->with([OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);
