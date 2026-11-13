<?php

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Invoices\InvoiceNumbers;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10 12:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->existing = function (string $number, ?Organization $organization = null): Invoice {
        $organization ??= $this->org;
        $contact = $organization->is($this->org)
            ? $this->jane
            : Contact::factory()->create(['organization_id' => $organization->id]);

        return Invoice::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'invoice_number' => $number,
        ]);
    };

    $this->createViaPage = function (): string {
        Livewire::test(CreateInvoice::class)
            ->fillForm(['contact_id' => $this->jane->id, 'amount' => 100])
            ->call('create')
            ->assertHasNoFormErrors();

        return Invoice::query()->latest('id')->value('invoice_number');
    };
});

test('the first invoice of the year is number 001', function () {
    expect(($this->createViaPage)())->toBe('INV-2026-001');
});

test('the next number follows the highest sequence numerically', function (array $existing, string $expected) {
    foreach ($existing as $number) {
        ($this->existing)($number);
    }

    expect(($this->createViaPage)())->toBe($expected);
})->with([
    'plain next' => [['INV-2026-004'], 'INV-2026-005'],
    'past 999' => [['INV-2026-999'], 'INV-2026-1000'],
    'after 1000' => [['INV-2026-999', 'INV-2026-1000'], 'INV-2026-1001'],
    'padded older sequence' => [['INV-2026-1000', 'INV-2026-0999'], 'INV-2026-1001'],
    'other year' => [['INV-2025-042'], 'INV-2026-001'],
    'off pattern' => [['INV-EUR', 'INV-2026-ABC', 'INV-2026-12'], 'INV-2026-001'],
    'too long to cast' => [['INV-2026-'.str_repeat('9', 19)], 'INV-2026-001'],
    'longest cast-safe sequence' => [['INV-2026-'.str_repeat('1', 18)], 'INV-2026-111111111111111112'],
]);

test('another organization numbers are ignored', function () {
    $globex = Organization::factory()->create();
    ($this->existing)('INV-2026-050', $globex);

    expect(($this->createViaPage)())->toBe('INV-2026-001');
    expect(Invoice::query()->forOrganization($globex->id)->sole()->invoice_number)->toBe('INV-2026-050');
});

test('the year is the organization local year', function () {
    $this->org->update(['timezone' => 'Africa/Tunis']);
    ($this->existing)('INV-2026-007');
    $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

    expect(($this->createViaPage)())->toBe('INV-2027-001');
});

test('an unknown stored timezone falls back to UTC', function () {
    $this->org->forceFill(['timezone' => 'Mars/Olympus'])->save();

    expect(InvoiceNumbers::preview($this->org->refresh()))->toBe('INV-2026-001');
});

test('the form shows a preview and explains it', function () {
    ($this->existing)('INV-2026-004');

    Livewire::test(CreateInvoice::class)
        ->assertFormSet(['invoice_number' => 'INV-2026-005'])
        ->assertSee('The final number is assigned when you save.');
});

test('a number taken after the form was opened is not reused', function () {
    $page = Livewire::test(CreateInvoice::class)->assertFormSet(['invoice_number' => 'INV-2026-001']);

    ($this->existing)('INV-2026-001');

    $page->fillForm(['contact_id' => $this->jane->id, 'amount' => 100])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::query()->latest('id')->value('invoice_number'))->toBe('INV-2026-002');
});

test('the lock is taken before the next number is read', function () {
    $page = Livewire::test(CreateInvoice::class)
        ->fillForm(['contact_id' => $this->jane->id, 'amount' => 100]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = [$query->sql, $query->bindings];
    });

    $page->call('create')->assertHasNoFormErrors();

    $lock = collect($queries)->search(fn (array $q): bool => str_contains($q[0], 'pg_advisory_xact_lock')
        && $q[1] === ['invoice-number:'.$this->org->id]);
    $read = collect($queries)->search(fn (array $q): bool => str_contains($q[0], 'substring(invoice_number from 10)'));

    expect($lock)->not->toBeFalse()
        ->and($read)->not->toBeFalse()
        ->and($lock)->toBeLessThan($read);
});

test('the edit page shows the stored number and has no helper text', function () {
    $invoice = ($this->existing)('INV-2026-003');

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertFormSet(['invoice_number' => 'INV-2026-003'])
        ->assertDontSee('The final number is assigned when you save.');
});

test('editing an invoice with a legacy free text number saves', function () {
    $invoice = ($this->existing)('INV-EUR');

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->fillForm(['notes' => 'edited'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->invoice_number)->toBe('INV-EUR')->and($invoice->notes)->toBe('edited');
});

test('the number is computed for the organization given, whatever the current tenant is', function () {
    $globex = Organization::factory()->create();
    ($this->existing)('INV-2026-009');
    ($this->existing)('INV-2026-050', $globex);

    expect(InvoiceNumbers::preview($globex))->toBe('INV-2026-051')
        ->and(InvoiceNumbers::preview($this->org))->toBe('INV-2026-010');
});

test('a number that only ends like a sequence is ignored', function () {
    ($this->existing)('XINV-2026-500');
    ($this->existing)('INV-2026-500X');

    expect(($this->createViaPage)())->toBe('INV-2026-001');
});

test('the page assigns its own number even when handed one', function () {
    $page = Livewire::test(CreateInvoice::class)->instance();
    $method = new ReflectionMethod($page, 'handleRecordCreation');

    $invoice = $method->invoke($page, [
        'contact_id' => $this->jane->id,
        'amount' => 100,
        'currency' => 'USD',
        'issued_at' => '2026-03-10',
        'due_at' => '2026-04-09',
        'invoice_number' => 'INV-2026-777',
        'organization_id' => $this->org->id,
    ]);

    expect($invoice->invoice_number)->toBe('INV-2026-001');
});
