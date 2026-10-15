<?php

use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Schemas\DealDrawerInfolist;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\PipelineStage;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->stage = fn (string $stage): PipelineStage => pipelineStageNamed($this->org->id, $stage);
    $this->stageEntry = function (string $schemaClass, Deal $deal): array {
        $schema = $schemaClass::configure(Schema::make(Livewire::test(ViewDeal::class, ['record' => $deal->id])->instance())->record($deal->fresh('pipelineStage')));
        $entry = collect($schema->getFlatComponents())->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === 'pipelineStage.name');

        return [$entry->getState(), $entry->getColor($entry->getState())];
    };
    $this->dealIn = fn (string $title, string $stage, array $attributes = []): Deal => Deal::factory()->inStage($stage)->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'status' => match ($stage) {
            'Won' => DealStatus::Won,
            'Lost' => DealStatus::Lost,
            default => DealStatus::Open,
        },
        ...$attributes,
    ]);
});

test('a renamed stage shows its new name and color in the list, the company tab, the page, the drawer and the widget', function () {
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd']);
    $deal = ($this->dealIn)('Retainer', 'Discovery', ['company_id' => $company->id]);
    ($this->stage)('Discovery')->update(['name' => 'Qualified', 'color' => 'success']);

    $list = Livewire::test(ListDeals::class)->assertCanSeeTableRecords([$deal]);
    $column = $list->instance()->getTable()->getColumn('pipelineStage.name');
    $tab = Livewire::test(DealsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class]);
    $tabColumn = $tab->instance()->getTable()->getColumn('pipelineStage.name');

    expect($list->html())->toContain('Qualified')->not->toContain('>Discovery<')
        ->and($column->record($deal->fresh('pipelineStage'))->getColor('Qualified'))->toBe('success')
        ->and($tab->html())->toContain('Qualified')
        ->and($tabColumn->record($deal->fresh('pipelineStage'))->getColor('Qualified'))->toBe('success')
        ->and(Livewire::test(ViewDeal::class, ['record' => $deal->id])->assertSuccessful()->html())->not->toContain('Discovery')
        ->and(($this->stageEntry)(DealInfolist::class, $deal))->toBe(['Qualified', 'success'])
        ->and(($this->stageEntry)(DealDrawerInfolist::class, $deal))->toBe(['Qualified', 'success'])
        ->and(Livewire::test(DealDetailsWidget::class, ['record' => $deal])->html())->toContain('Qualified')->toContain('fi-color-success');
});

test('sorting by stage follows the pipeline order, not the alphabet', function () {
    $lost = ($this->dealIn)('A', 'Lost');
    $lead = ($this->dealIn)('B', 'Lead');
    $discovery = ($this->dealIn)('C', 'Discovery');

    Livewire::test(ListDeals::class)
        ->sortTable('pipelineStage.name')
        ->assertCanSeeTableRecords([$lead, $discovery, $lost], inOrder: true)
        ->sortTable('pipelineStage.name', 'desc')
        ->assertCanSeeTableRecords([$lost, $discovery, $lead], inOrder: true);
});

test('the company deals tab sorts by stage in pipeline order too', function () {
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd']);
    $lost = ($this->dealIn)('A', 'Lost', ['company_id' => $company->id]);
    $lead = ($this->dealIn)('B', 'Lead', ['company_id' => $company->id]);
    $discovery = ($this->dealIn)('C', 'Discovery', ['company_id' => $company->id]);

    Livewire::test(DealsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
        ->sortTable('pipelineStage.name')
        ->assertCanSeeTableRecords([$lead, $discovery, $lost], inOrder: true);
});

test('filtering by several stage ids lists their deals only', function () {
    $lead = ($this->dealIn)('A', 'Lead');
    $won = ($this->dealIn)('B', 'Won');
    $lost = ($this->dealIn)('C', 'Lost');

    Livewire::test(ListDeals::class)
        ->filterTable('pipeline_stage_id', [($this->stage)('Lead')->id, ($this->stage)('Won')->id])
        ->assertCanSeeTableRecords([$lead, $won])
        ->assertCanNotSeeTableRecords([$lost]);
});

test('the stage filter offers this organization stages in pipeline order, never another one', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = pipelineStageNamed($other->id, 'Lead');

    $options = Livewire::test(ListDeals::class)->instance()->getTable()->getFilter('pipeline_stage_id')->getOptions();

    expect(array_values($options))->toBe(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost'])
        ->and(array_keys($options))->not->toContain($foreign->id);
});

test('the open deals breakdown uses the stage names in pipeline order and leaves out closed deals', function () {
    ($this->dealIn)('A', 'Negotiating');
    ($this->dealIn)('B', 'Lead');
    ($this->dealIn)('C', 'Lead');
    ($this->dealIn)('D', 'Won');
    ($this->dealIn)('E', 'Won', ['status' => DealStatus::Open]);
    ($this->stage)('Lead')->update(['name' => 'Cold']);

    expect(Livewire::test(DealsOverviewWidget::class)->instance()->getOpenStageBreakdown())->toBe(['Cold' => 2, 'Negotiating' => 1]);
});

test('the invoice PDF names the pipeline stage of its deal', function () {
    $deal = ($this->dealIn)('Retainer', 'Discovery');
    ($this->stage)('Discovery')->update(['name' => 'Qualified']);
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'deal_id' => $deal->id,
        'status' => InvoiceStatus::Sent,
    ]);
    $invoice->load(['contact', 'deal.pipelineStage', 'organization']);

    $html = view('pdf.invoice', ['invoice' => $invoice, 'organization' => $this->org])->render();

    expect($html)->toContain('Qualified');
    $this->get(route('invoices.pdf', $invoice))->assertOk();
});

test('a deal in a lost stage is suffixed in the invoices list', function () {
    $lost = ($this->dealIn)('Pen test', 'Lost');
    $open = ($this->dealIn)('Audit', 'Lead');
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $lostInvoice = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'deal_id' => $lost->id, 'status' => InvoiceStatus::Sent]);
    $openInvoice = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'deal_id' => $open->id, 'status' => InvoiceStatus::Sent]);

    Livewire::test(ListInvoices::class)
        ->assertTableColumnFormattedStateSet('deal.title', 'Pen test (Lost)', $lostInvoice)
        ->assertTableColumnFormattedStateSet('deal.title', 'Audit', $openInvoice);
});
