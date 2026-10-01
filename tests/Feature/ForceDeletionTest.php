<?php

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

it('refuses to permanently delete records', function (Closure $makeRecord) {
    $record = $makeRecord->call($this);

    expect(fn () => $record->forceDelete())->toThrow(LogicException::class, 'cannot be permanently deleted');
    $this->assertNotSoftDeleted($record);

    $record->delete();

    expect(fn () => $record->forceDelete())->toThrow(LogicException::class);
    $this->assertSoftDeleted($record);
})->with([
    'contact' => fn () => Contact::factory()->for($this->org)->create(),
    'company' => fn () => Company::factory()->for($this->org)->create(),
    'company type' => fn () => CompanyType::factory()->for($this->org)->create(),
    'custom field' => fn () => CustomField::factory()->for($this->org)->create(),
    'company custom field' => fn () => CompanyCustomField::factory()->for($this->org)->create([
        'company_type_id' => CompanyType::factory()->for($this->org)->create()->id,
    ]),
    'segment' => fn () => Segment::factory()->for($this->org)->create(),
    'tag' => fn () => Tag::factory()->for($this->org)->create(),
    'address' => fn () => Address::factory()->create(['organization_id' => $this->org->id]),
]);

it('offers no force delete action for contacts', function () {
    $contact = Contact::factory()->for($this->org)->create();
    $contact->delete();

    Livewire::test(ListContacts::class)
        ->filterTable('trashed', true)
        ->assertTableActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('forceDelete');

    Livewire::test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionVisible('restore');
});

it('offers no force delete action for companies', function () {
    $company = Company::factory()->for($this->org)->create();
    $company->delete();

    Livewire::test(ListCompanies::class)
        ->filterTable('trashed', true)
        ->assertTableActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('forceDelete');

    Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionVisible('restore');
});
