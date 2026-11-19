<?php

use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Deals\Widgets\DealInvoicesWidget;
use App\Filament\Widgets\DealsNeedingAttentionWidget;
use App\Filament\Widgets\MyDealsClosingWidget;
use App\Models\Company;
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

    $this->pipelineCardValue = fn (Deal $deal): ?string => collect(Livewire::test(DealPipeline::class)->instance()->getBoard()->getCardSchema($deal)->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === 'value')
        ?->getState();

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
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
        ->fillForm(['title' => 'No currency', 'value' => 1000])
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
        'status' => InvoiceStatus::Partial,
    ]);

    $widget = Livewire::test(DealInvoicesWidget::class, ['record' => $this->deal]);

    expect(substr_count($widget->html(), '€2,000.00'))->toBe(2);

    $widget->assertSee('€500.00')
        ->assertSee('€1,500.00')
        ->assertDontSee('$2,000.00');
});

test('the pipeline card shows the value in the currency of the organization', function () {
    expect(($this->pipelineCardValue)($this->deal))->toBe('€1,234.50');
});

test('a default organization shows dollars', function () {
    $this->org->update(['currency' => Currency::Usd]);

    Livewire::test(ListDeals::class)->assertSee('$1,234.50')->assertDontSee('€1,234.50');
    expect(($this->pipelineCardValue)($this->deal))->toBe('$1,234.50');
});

describe('in a TND organization', function () {
    beforeEach(function () {
        $this->org->update(['currency' => Currency::Tnd]);
        $this->nbsp = "\u{00A0}";
        $this->tndColumn = fn ($component, string $name = 'value') => ($column = $component->instance()->getTable()->getColumn($name)->record($this->deal)) ? $column->formatState($column->getState()) : null;
    });

    test('the deals list shows two decimals', function () {
        Livewire::test(ListDeals::class)
            ->assertSee("TND{$this->nbsp}1,234.50")
            ->assertDontSee("TND{$this->nbsp}1,234.500");
    });

    test('the deals list value column is formatted with two decimals', function () {
        expect(($this->tndColumn)(Livewire::test(ListDeals::class)))->toBe("TND{$this->nbsp}1,234.50");
    });

    test('the deal infolist value entry is formatted with two decimals', function () {
        $livewire = Livewire::test(ListDeals::class)->instance();
        $schema = DealInfolist::configure(Schema::make($livewire))->record($this->deal);

        $entry = collect($schema->getFlatComponents())
            ->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === 'value');

        expect($entry->formatState($entry->getState()))->toBe("TND{$this->nbsp}1,234.50");
    });

    test('the deal details widget shows two decimals', function () {
        Livewire::test(DealDetailsWidget::class, ['record' => $this->deal])
            ->assertSee("TND{$this->nbsp}1,234.50")
            ->assertDontSee("TND{$this->nbsp}1,234.500");
    });

    test('the pipeline card value shows two decimals', function () {
        expect(($this->pipelineCardValue)($this->deal))->toBe("TND{$this->nbsp}1,234.50");
    });

    test('the pipeline drawer shows two decimals', function () {
        Livewire::test(DealPipeline::class)
            ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id])
            ->assertMountedActionModalSee("TND{$this->nbsp}1,234.50")
            ->assertMountedActionModalDontSee("TND{$this->nbsp}1,234.500");
    });

    test('the deals relation manager of a company shows two decimals', function () {
        $company = Company::factory()->create(['organization_id' => $this->org->id]);
        $this->deal->update(['company_id' => $company->id]);

        $manager = Livewire::test(DealsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class]);

        expect(($this->tndColumn)($manager))->toBe("TND{$this->nbsp}1,234.50");
    });

    test('the my deals closing widget shows two decimals', function () {
        $this->deal->update(['owner_id' => $this->user->id, 'expected_close_date' => now($this->org->timezone)->subDay()->toDateString()]);

        $widget = Livewire::test(MyDealsClosingWidget::class)->assertCanSeeTableRecords([$this->deal]);

        expect(($this->tndColumn)($widget))->toBe("TND{$this->nbsp}1,234.50");
    });

    test('the deals needing attention widget shows two decimals', function () {
        $this->deal->update(['expected_close_date' => now($this->org->timezone)->subDays(3)->toDateString()]);

        $widget = Livewire::test(DealsNeedingAttentionWidget::class)->assertCanSeeTableRecords([$this->deal]);

        expect(($this->tndColumn)($widget))->toBe("TND{$this->nbsp}1,234.50");
    });
});
