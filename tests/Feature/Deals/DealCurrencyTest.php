<?php

use App\Enums\Currency;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Deals\Widgets\DealInvoicesWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Eur]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'value' => 1234.5,
        'position' => '1000.0000000000',
    ]);
});

test('the deal forms have no currency field', function () {
    Livewire::test(CreateDeal::class)->assertFormFieldDoesNotExist('currency');
    Livewire::test(EditDeal::class, ['record' => $this->deal->id])->assertFormFieldDoesNotExist('currency');
});

test('a deal is created from title, stage and value and carries no currency', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'No currency', 'stage' => DealStage::Lead, 'value' => 1000])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'No currency')->firstOrFail();

    expect($deal->getAttributes())->not->toHaveKey('currency')
        ->and($deal->value)->toBe('1000.00');
});

test('the deals table shows the value in the currency of the organization', function () {
    Livewire::test(ListDeals::class)->assertSee('€1,234.50');
});

test('the deal infolist shows the value in the currency of the organization', function () {
    $livewire = Livewire::test(ListDeals::class)->instance();
    $schema = DealInfolist::configure(Schema::make($livewire))->record($this->deal);

    $entry = collect($schema->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === 'value');

    expect($entry->formatState($entry->getState()))->toBe('€1,234.50');
});

test('the deal details widget shows the value in the currency of the organization', function () {
    Livewire::test(DealDetailsWidget::class, ['record' => $this->deal])->assertSee('€1,234.50');
});

test('the invoices widget of a deal shows amounts and totals in the currency of the organization', function () {
    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'deal_id' => $this->deal->id,
        'amount' => 2000,
        'amount_paid' => 500,
    ]);

    $widget = Livewire::test(DealInvoicesWidget::class, ['record' => $this->deal]);

    expect(substr_count($widget->html(), '€2,000.00'))->toBe(2);

    $widget->assertSee('€500.00')
        ->assertSee('€1,500.00')
        ->assertDontSee('$2,000.00');
});

test('the pipeline card shows the value in the currency of the organization', function () {
    Livewire::test(DealPipeline::class)->assertSee('€1,234.50');
});

test('a default organization shows dollars', function () {
    $this->org->update(['currency' => Currency::Usd]);

    Livewire::test(ListDeals::class)->assertSee('$1,234.50')->assertDontSee('€1,234.50');
    Livewire::test(DealPipeline::class)->assertSee('$1,234.50');
});
