<?php

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->company = Company::factory()->create(['organization_id' => $this->org->id]);
});

test('a contact can work for several companies and a company can have several contacts', function () {
    $otherCompany = Company::factory()->create(['organization_id' => $this->org->id]);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $colleague = Contact::factory()->create(['organization_id' => $this->org->id]);

    $contact->companies()->attach([$this->company->id, $otherCompany->id]);
    $colleague->companies()->attach($this->company->id);

    expect($contact->companies)->toHaveCount(2)
        ->and($this->company->contacts->pluck('id')->sort()->values()->all())->toBe([$contact->id, $colleague->id]);
});

test('relation manager lists the contacts of the company', function () {
    $attached = Contact::factory()->count(2)->create(['organization_id' => $this->org->id]);
    $notAttached = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->company->contacts()->attach($attached);

    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($attached)
        ->assertCanNotSeeTableRecords([$notAttached]);
});

test('can attach contacts to a company', function () {
    $contacts = Contact::factory()->count(2)->create(['organization_id' => $this->org->id]);

    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => $contacts->pluck('id')->all()])
        ->assertHasNoActionErrors();

    expect($this->company->contacts()->pluck('contacts.id')->sort()->values()->all())
        ->toBe($contacts->pluck('id')->sort()->values()->all());
});

test('cannot attach a contact from another organization', function () {
    $foreignContact = Contact::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$foreignContact->id]]);

    expect($this->company->contacts()->count())->toBe(0);
});

test('can detach a contact from a company without deleting it', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->company->contacts()->attach($contact);

    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => EditCompany::class,
    ])
        ->callAction(TestAction::make(DetachAction::class)->table($contact));

    expect($this->company->contacts()->count())->toBe(0);
    $this->assertModelExists($contact);
});

test('can assign companies when creating a contact', function () {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'companies' => [$this->company->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::query()->where('email', 'jane@example.com')->firstOrFail()->companies->pluck('id')->all())
        ->toBe([$this->company->id]);
});

test('can change the companies of a contact', function () {
    $newCompany = Company::factory()->create(['organization_id' => $this->org->id]);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $contact->companies()->attach($this->company);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->assertFormSet(['companies' => [$this->company->id]])
        ->fillForm(['companies' => [$newCompany->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->companies->pluck('id')->all())->toBe([$newCompany->id]);
});

test('contact form only offers companies of the current organization', function () {
    $foreignCompany = Company::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    Livewire::test(CreateContact::class)
        ->assertFormFieldExists('companies', fn ($field): bool => array_key_exists($this->company->id, $field->getOptions())
            && ! array_key_exists($foreignCompany->id, $field->getOptions()));
});

test('contacts table shows the companies of each contact', function () {
    $this->company->update(['name' => 'Acme Corp']);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $contact->companies()->attach($this->company);

    Livewire::test(ListContacts::class)
        ->assertTableColumnStateSet('companies.name', ['Acme Corp'], $contact);
});

test('deleting a contact removes it from its companies', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->company->contacts()->attach($contact);

    $contact->delete();

    expect($this->company->contacts()->count())->toBe(0);
});
