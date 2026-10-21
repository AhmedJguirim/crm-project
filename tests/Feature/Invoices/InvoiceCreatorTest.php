<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Archer']);
    $this->org = $this->alice->personalOrganization();
    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->creatorInvoice = fn (array $attributes = []): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        ...$attributes,
    ]);
});

afterEach(function () {
    Filament::setTenant(null);
});

test('the invoice page records and shows its creator', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'amount' => 500,
            'issued_at' => '2026-10-21',
            'due_at' => '2026-11-21',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->latest('id')->firstOrFail();

    expect($invoice->created_by)->toBe($this->alice->id)
        ->and($invoice->creator->is($this->alice))->toBeTrue();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertSeeInOrder(['Created by', 'Alice Archer']);
});

test('a forged creator is ignored', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'amount' => 500,
            'issued_at' => '2026-10-21',
            'due_at' => '2026-11-21',
        ])
        ->set('data.created_by', $this->bob->id)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::query()->latest('id')->firstOrFail()->created_by)->toBe($this->alice->id);
});

test('the create page overrides a creator that reaches the form data', function () {
    $page = Livewire::test(CreateInvoice::class)->instance();

    $data = (fn (array $data): array => $this->mutateFormDataBeforeCreate($data))->call($page, ['created_by' => $this->bob->id]);

    expect($data['created_by'])->toBe($this->alice->id);
});

test('the observer fills the creator only when empty', function () {
    expect(($this->creatorInvoice)()->created_by)->toBe($this->alice->id)
        ->and(($this->creatorInvoice)(['created_by' => $this->bob->id])->created_by)->toBe($this->bob->id);

    Auth::logout();

    expect(($this->creatorInvoice)()->created_by)->toBeNull();
});

test('editing keeps the creator', function () {
    $invoice = ($this->creatorInvoice)(['created_by' => $this->bob->id]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['notes' => 'Edited by Alice'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->notes)->toBe('Edited by Alice')
        ->and($invoice->created_by)->toBe($this->bob->id);
});

test('an invoice without a creator shows a dash', function () {
    Auth::logout();
    $invoice = ($this->creatorInvoice)();
    $this->actingAs($this->alice);

    expect($invoice->created_by)->toBeNull();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertSeeInOrder(['Created by', '—']);
});

test('deleting the creator keeps the invoice', function () {
    $invoice = ($this->creatorInvoice)(['created_by' => $this->bob->id]);

    $this->bob->delete();

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue()
        ->and($invoice->refresh()->created_by)->toBeNull();
});
