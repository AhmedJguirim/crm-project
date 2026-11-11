<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function currencySelectFormField(Testable $page, string $name): Field
{
    return collect($page->instance()->getSchema('form')->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof Field && $component->getName() === $name);
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Tnd]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Sami Ben Ali']);

    $this->makeInvoice = fn (array $attributes = [], ?Currency $currency = null, ?string $rate = null): Invoice => Invoice::factory()
        ->inCurrency($currency ?? Currency::Tnd, $rate)
        ->create($attributes + [
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'amount' => 1000,
            'amount_paid' => 0,
        ]);

    ($this->makeInvoice)(['invoice_number' => 'INV-2026-001', 'status' => InvoiceStatus::Sent, 'issued_at' => '2026-10-01'], Currency::Eur, '3.35');
    $this->latest = ($this->makeInvoice)(['invoice_number' => 'INV-2026-002', 'status' => InvoiceStatus::Sent, 'issued_at' => '2026-11-01'], Currency::Eur, '3.4');
    ($this->makeInvoice)(['invoice_number' => 'INV-2026-003', 'status' => InvoiceStatus::Draft, 'issued_at' => '2026-11-05'], Currency::Eur, '5');

    $globex = Organization::factory()->create(['currency' => Currency::Tnd]);
    app(TenantContext::class)->run($globex->id, fn () => Invoice::factory()->inCurrency(Currency::Eur, '9.99')->create([
        'organization_id' => $globex->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $globex->id])->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-11-10',
    ]));

    $this->draftEur = fn (?string $rate = null): Invoice => ($this->makeInvoice)(['status' => InvoiceStatus::Draft, 'issued_at' => '2026-11-06'], Currency::Eur, $rate);

    $this->createWith = fn (array $data) => Livewire::test(CreateInvoice::class)
        ->fillForm(['contact_id' => $this->contact->id, 'amount' => '1000'] + $data)
        ->call('create');
});

test('the create form offers TND, EUR and USD, defaults to the home currency and hides the rate', function () {
    $page = Livewire::test(CreateInvoice::class);

    expect(currencySelectFormField($page, 'currency')->getOptions())->toBe([
        'TND' => 'Tunisian dinar (TND)',
        'EUR' => 'Euro (EUR)',
        'USD' => 'US dollar (USD)',
    ]);

    $page->assertFormSet(['currency' => 'TND'])->assertFormFieldHidden('exchange_rate');

    expect(currencySelectFormField($page, 'amount')->getPrefixLabel())->toBe('TND');
});

test('an organization in another currency also gets its own currency', function () {
    $this->org->update(['currency' => Currency::Gbp]);

    $page = Livewire::test(CreateInvoice::class)->assertFormSet(['currency' => 'GBP']);

    expect(array_keys(currencySelectFormField($page, 'currency')->getOptions()))->toBe(['TND', 'EUR', 'USD', 'GBP']);
});

test('choosing EUR prefills the last issued EUR rate of my organization', function () {
    $page = Livewire::test(CreateInvoice::class)
        ->fillForm(['currency' => 'EUR'])
        ->assertFormFieldVisible('exchange_rate')
        ->assertFormSet(['exchange_rate' => '3.4']);

    expect(currencySelectFormField($page, 'amount')->getPrefixLabel())->toBe('EUR')
        ->and(currencySelectFormField($page, 'exchange_rate')->getPrefixLabel())->toBe('1 EUR =')
        ->and(currencySelectFormField($page, 'exchange_rate')->getSuffixLabel())->toBe('TND');
});

test('a currency never used has no prefill and going back home clears the rate', function () {
    ($this->makeInvoice)(['status' => InvoiceStatus::Sent, 'issued_at' => '2026-11-07']);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['currency' => 'USD'])
        ->assertFormFieldVisible('exchange_rate')
        ->assertFormSet(['exchange_rate' => null])
        ->fillForm(['exchange_rate' => '3.1'])
        ->fillForm(['currency' => 'TND'])
        ->assertFormFieldHidden('exchange_rate')
        ->assertFormSet(['exchange_rate' => null]);
});

test('a foreign draft is saved with or without a rate', function () {
    ($this->createWith)(['currency' => 'EUR', 'exchange_rate' => '3.45'])->assertHasNoFormErrors();

    $first = Invoice::query()->latest('id')->firstOrFail();
    expect($first->status)->toBe(InvoiceStatus::Draft)
        ->and($first->currency)->toBe(Currency::Eur)
        ->and($first->exchange_rate)->toBe('3.45000000');

    ($this->createWith)(['currency' => 'EUR', 'exchange_rate' => ''])->assertHasNoFormErrors();

    $second = Invoice::query()->latest('id')->firstOrFail();
    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(InvoiceStatus::Draft)
        ->and($second->exchange_rate)->toBeNull();
});

test('a home-currency invoice created from the form has no rate even when one was typed before', function () {
    ($this->createWith)(['currency' => 'TND', 'exchange_rate' => '5'])->assertHasNoFormErrors();

    expect(Invoice::query()->latest('id')->firstOrFail())
        ->currency->toBe(Currency::Tnd)
        ->exchange_rate->toBeNull();
});

test('the rate is validated on the create form', function (string $rate, string $rule, string $message) {
    $page = ($this->createWith)(['currency' => 'EUR', 'exchange_rate' => $rate]);

    $page->assertHasFormErrors(['exchange_rate' => $rule]);

    expect($page->errors()->get('data.exchange_rate'))->toContain($message);
})->with([
    'zero' => ['0', 'gt', 'The exchange rate must be a number greater than 0.'],
    'negative' => ['-1', 'gt', 'The exchange rate must be a number greater than 0.'],
    'text' => ['abc', 'numeric', 'The exchange rate must be a number greater than 0.'],
    'nine decimals' => ['3.123456789', 'decimal', 'The exchange rate can have at most 8 decimal places.'],
    'too big' => ['10000000000', 'max', "The exchange rate can't be more than 9,999,999,999."],
]);

test('the largest and smallest rates are accepted', function (string $rate) {
    ($this->createWith)(['currency' => 'EUR', 'exchange_rate' => $rate])->assertHasNoFormErrors();
})->with(['9999999999', '0.00000001', '3.40000000']);

test('issuing a foreign draft from the edit form needs the rate', function () {
    $invoice = ($this->draftEur)();

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertFormSet(['exchange_rate' => '3.4'])
        ->fillForm(['exchange_rate' => '', 'status' => InvoiceStatus::Sent->value])
        ->call('save')
        ->assertHasFormErrors(['exchange_rate' => 'required']);

    expect($page->errors()->get('data.exchange_rate'))->toContain('Enter the exchange rate to issue an invoice in another currency.');

    $page->fillForm(['exchange_rate' => '3.5'])->call('save')->assertHasNoFormErrors();

    expect($invoice->refresh())
        ->status->toBe(InvoiceStatus::Sent)
        ->exchange_rate->toBe('3.50000000');
});

test('the failed issue leaves the invoice a draft', function () {
    $invoice = ($this->draftEur)();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['exchange_rate' => '', 'status' => InvoiceStatus::Sent->value])
        ->call('save')
        ->assertHasFormErrors(['exchange_rate' => 'required']);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

test('a foreign draft can be cancelled without a rate', function () {
    $invoice = ($this->draftEur)();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['exchange_rate' => '', 'status' => InvoiceStatus::Cancelled->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh())
        ->status->toBe(InvoiceStatus::Cancelled)
        ->exchange_rate->toBeNull();
});

test('a stored rate is shown trimmed', function () {
    $invoice = ($this->draftEur)('3.45');

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])->assertFormSet(['exchange_rate' => '3.45']);
});

test('an issued invoice locks currency and rate, even against forged values', function () {
    config(['app.debug' => true]);

    $page = Livewire::test(EditInvoice::class, ['record' => $this->latest->id])
        ->assertFormFieldDisabled('currency')
        ->assertFormFieldDisabled('exchange_rate')
        ->assertSee("The currency and exchange rate can't change once the invoice is issued.");

    $page->set('data.currency', 'USD')
        ->set('data.exchange_rate', '9')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->latest->refresh())
        ->currency->toBe(Currency::Eur)
        ->exchange_rate->toBe('3.40000000');
});

test('forged currency values give a validation error, never a 500', function (mixed $value) {
    config(['app.debug' => true]);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['contact_id' => $this->contact->id, 'amount' => '100'])
        ->set('data.currency', $value)
        ->call('create')
        ->assertHasFormErrors(['currency']);

    expect(Invoice::query()->where('contact_id', $this->contact->id)->where('amount', 100)->exists())->toBeFalse();
})->with([
    'a currency not offered' => 'GBP',
    'an unknown code' => 'XXX',
    'an array' => [['EUR']],
]);

test('an array sent to the rate gives no 500', function () {
    config(['app.debug' => true]);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['contact_id' => $this->contact->id, 'amount' => '100', 'currency' => 'EUR'])
        ->set('data.exchange_rate', ['3.4'])
        ->call('create');

    expect(Invoice::query()->where('contact_id', $this->contact->id)->where('amount', 100)->first()?->exchange_rate)->toBeNull();
});

test('choosing a deal fills the amount only in the home currency', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'value' => 5000,
    ]);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['contact_id' => $this->contact->id, 'currency' => 'EUR'])
        ->set('data.deal_id', $deal->id)
        ->assertFormSet(['amount' => null])
        ->fillForm(['currency' => 'TND'])
        ->set('data.deal_id', null)
        ->set('data.deal_id', $deal->id)
        ->assertFormSet(['amount' => '5000.00']);
});

test('mark as sent on a home-currency draft has no modal', function () {
    $invoice = ($this->makeInvoice)(['status' => InvoiceStatus::Draft]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->mountAction('markAsSent')
        ->assertActionNotMounted();

    expect($invoice->refresh())
        ->status->toBe(InvoiceStatus::Sent)
        ->exchange_rate->toBe('1.00000000');
});

test('mark as sent on a foreign draft asks for the rate, prefilled', function () {
    $invoice = ($this->draftEur)();

    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->mountAction('markAsSent')
        ->assertActionDataSet(['exchange_rate' => '3.4'])
        ->assertMountedActionModalSee('The exchange rate is frozen once the invoice is sent.')
        ->setActionData(['exchange_rate' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['exchange_rate' => 'required']);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);

    $page->setActionData(['exchange_rate' => '3.38'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($invoice->refresh())
        ->status->toBe(InvoiceStatus::Sent)
        ->exchange_rate->toBe('3.38000000');
});

test('mark as sent refuses a rate that is not valid', function (string $rate) {
    $invoice = ($this->draftEur)();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->mountAction('markAsSent')
        ->setActionData(['exchange_rate' => $rate])
        ->callMountedAction()
        ->assertHasActionErrors(['exchange_rate']);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
})->with(['0', '-2', 'abc', '3.123456789']);

test('mark as sent keeps a rate typed on the draft', function () {
    $invoice = ($this->draftEur)('3.45');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->mountAction('markAsSent')
        ->assertActionDataSet(['exchange_rate' => '3.45']);
});

test('a viewer cannot mark as sent', function () {
    $invoice = ($this->draftEur)();

    $viewer = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
    $this->actingAs($viewer);
    Filament::setTenant($this->org);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden('markAsSent');
});

test('the latest rate is per organization, per currency and ignores drafts', function () {
    Filament::setTenant($this->org);

    expect(Invoice::latestExchangeRate(Currency::Eur))->toBe('3.4')
        ->and(Invoice::latestExchangeRate(Currency::Usd))->toBeNull()
        ->and(Invoice::trimExchangeRate('10.00000000'))->toBe('10')
        ->and(Invoice::trimExchangeRate('0.30750000'))->toBe('0.3075')
        ->and(Invoice::trimExchangeRate('100'))->toBe('100')
        ->and(Invoice::trimExchangeRate(null))->toBeNull();
});

test('two invoices issued the same day use the higher id as the latest', function () {
    ($this->makeInvoice)(['status' => InvoiceStatus::Sent, 'issued_at' => '2026-11-01'], Currency::Eur, '3.5');

    expect(Invoice::latestExchangeRate(Currency::Eur))->toBe('3.5');
});
