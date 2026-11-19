<?php

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Segments\SegmentResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    config(['app.debug' => false]);

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);
});

dataset('malformed record pages', [
    'contacts view' => [ContactResource::class, 'view'],
    'contacts edit' => [ContactResource::class, 'edit'],
    'deals view' => [DealResource::class, 'view'],
    'deals edit' => [DealResource::class, 'edit'],
    'invoices view' => [InvoiceResource::class, 'view'],
    'invoices edit' => [InvoiceResource::class, 'edit'],
    'segments view' => [SegmentResource::class, 'view'],
    'segments rules' => [SegmentResource::class, 'rules'],
    'companies edit' => [CompanyResource::class, 'edit'],
    'company custom fields edit' => [CompanyCustomFieldResource::class, 'edit'],
    'custom fields edit' => [CustomFieldResource::class, 'edit'],
    'tags edit' => [TagResource::class, 'edit'],
    'tasks edit' => [TaskResource::class, 'edit'],
]);

dataset('malformed record keys', [
    'letters' => 'abc',
    'twenty digits' => '99999999999999999999',
    'one past bigint' => '9223372036854775808',
    'decimal' => '1.5',
    'zero' => '0',
    'negative' => '-1',
    'leading zeros' => '007',
]);

it('answers 404 on every record page for a malformed record id', function (string $resource, string $page, string $key) {
    $this->get($resource::getUrl($page, ['record' => $key]))->assertNotFound();
})->with('malformed record pages')->with('malformed record keys');

it('answers 404 for a padded or decimal spelling of a real record id', function (string $spelling) {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann']);

    $this->get(ContactResource::getUrl('edit', ['record' => str_replace('{id}', (string) $contact->id, $spelling)]))->assertNotFound();
})->with(['leading zero' => '0{id}', 'decimal' => '{id}.0', 'exponent' => '{id}e0', 'plus sign' => '+{id}']);

it('answers 404 for the largest readable id that does not exist', function () {
    $this->get(ContactResource::getUrl('edit', ['record' => '9223372036854775807']))->assertNotFound();
});

it('still opens a real record', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann']);

    $this->get(ContactResource::getUrl('edit', ['record' => $contact]))->assertOk();
});

it('still answers 404 to a member of another organization opening a real id', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann']);

    $stranger = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($stranger);
    Filament::setTenant($stranger->personalOrganization());

    $this->get(ContactResource::getUrl('edit', ['record' => $contact->id]))->assertNotFound();
});
