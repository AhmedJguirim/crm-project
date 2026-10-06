<?php

use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

it('refuses the names the import uses', function (string $name) {
    $page = Livewire::test(CreateCustomField::class)
        ->fillForm(['name' => $name, 'type' => 'text'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect($page->errors()->get('data.name'))->toBe(['This name is used by the import. Choose another name.'])
        ->and(CustomField::count())->toBe(0);
})->with(['Tags', ' email', 'Lead Source', 'Company', 'STATUS', 'Company Website', 'company type', '_row_number', '_error', 'name', 'Phone', 'Company Phone', 'company annual revenue', ' Company: Region', 'company:Region', 'COMPANY :  VAT']);

it('refuses to rename a field to a reserved name', function () {
    $field = CustomField::factory()->for($this->org)->create(['name' => 'Website', 'type' => 'url']);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'Email'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($field->fresh()->name)->toBe('Website');
});

it('lets an existing field be saved without changing its name', function () {
    $field = CustomField::factory()->for($this->org)->create(['name' => 'Website', 'type' => 'url']);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'Website'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('accepts a name that only contains a reserved word', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm(['name' => 'Company size', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('accepts a name that merely starts like the company prefix', function (string $name) {
    Livewire::test(CreateCustomField::class)
        ->fillForm(['name' => $name, 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();
})->with(['Company culture: strong', 'My company: Region', 'Companyx:y']);
