<?php

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.debug' => true]);
    $this->travelTo(Carbon::parse('2026-03-10 12:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id]);
});

test('a forged invoice number on create is ignored', function (string $forged) {
    Livewire::test(CreateInvoice::class)
        ->set('data.invoice_number', $forged)
        ->fillForm(['contact_id' => $this->jane->id, 'amount' => 100])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->sole()->invoice_number)->toBe('INV-2026-001');
})->with([
    'valid looking' => ['INV-2026-777'],
    'slashes' => ['A/B\C'],
    'quote' => ['INV-2026-001"; x'],
    'crlf' => ["INV-2026-001\r\nX-Injected: 1"],
    'too long' => [str_repeat('A', 300)],
    'empty' => [''],
]);

test('a forged invoice number on edit is ignored', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->jane->id,
        'invoice_number' => 'INV-2026-003',
    ]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->set('data.invoice_number', 'INV-2026-777')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->invoice_number)->toBe('INV-2026-003');
});

test('forging another organization sequence does not shift ours', function () {
    $globex = Organization::factory()->create();
    $globexInvoice = Invoice::factory()->create([
        'organization_id' => $globex->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $globex->id])->id,
        'invoice_number' => 'INV-2026-050',
    ]);

    Livewire::test(CreateInvoice::class)
        ->set('data.invoice_number', 'INV-2026-051')
        ->fillForm(['contact_id' => $this->jane->id, 'amount' => 100])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::query()->latest('id')->value('invoice_number'))->toBe('INV-2026-001')
        ->and($globexInvoice->refresh()->invoice_number)->toBe('INV-2026-050')
        ->and(Invoice::query()->forOrganization($globex->id)->count())->toBe(1);
});
