<?php

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager as CompanyContactsRelationManager;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Widgets\DealActivityFeed;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Deals\Widgets\DealInvoicesWidget;
use App\Filament\Resources\Segments\Widgets\SegmentStatsOverview;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Segment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.debug' => false]);

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee']);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Ray']);
    $this->acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);
    $this->retainer = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->ann->id, 'title' => 'Retainer']);
    $this->segment = Segment::factory()->for($this->org)->published()->create(['name' => 'Leads']);
});

test('a forged sub-key update below a scalar or null property answers 419', function (string $component, string $path) {
    $parameters = match ($component) {
        EditContact::class => ['record' => $this->ann->id],
        EditCompany::class => ['record' => $this->acme->id],
        CompanyContactsRelationManager::class => ['ownerRecord' => $this->acme, 'pageClass' => EditCompany::class],
        ContactActivityFeed::class => ['record' => $this->ann],
        default => [],
    };

    Livewire::test($component, $parameters)->set($path, 'x')->assertStatus(419);
})->with([
    'search of the contacts' => [ListContacts::class, 'tableSearch.0'],
    'search deeper below a string' => [ListContacts::class, 'tableSearch.a.b'],
    'null sort of the contacts' => [ListContacts::class, 'tableSort.0'],
    'tab of the deals' => [ListDeals::class, 'activeTab.0'],
    'search of the board' => [DealPipeline::class, 'tableSearch.0'],
    'name in the contact form' => [EditContact::class, 'data.name.0'],
    'name in the company form' => [EditCompany::class, 'data.name.0'],
    'search of a relation manager' => [CompanyContactsRelationManager::class, 'tableSearch.0'],
    'null date of the feed widget' => [ContactActivityFeed::class, 'dateFrom.0'],
]);

test('the refusal does not depend on debug mode', function () {
    config(['app.debug' => true]);

    Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);
});

test('a forged sub-key update over raw http answers 419 and the plain update answers 200', function () {
    $snapshot = json_encode(Livewire::test(ListContacts::class)->snapshot);

    $send = fn (array $updates) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
    ], ['X-Livewire' => 'true']);

    $send(['tableSearch.0' => 'x'])->assertStatus(419);
    $send(['tableSearch' => 'Ann'])->assertOk();
});

test('legitimate updates keep working', function () {
    Livewire::test(ListContacts::class)
        ->set('tableSearch', 'Ann')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$this->ann])
        ->assertCanNotSeeTableRecords([$this->bob]);

    Livewire::test(ListContacts::class)
        ->set('tableFilters.owner_id.value', (string) $this->owner->id)
        ->assertSuccessful();

    Livewire::test(EditContact::class, ['record' => $this->ann->id])
        ->set('data.name', 'Ann Smith')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->ann->fresh()->name)->toBe('Ann Smith');
});

test('a missing nested key is left to Livewire', function () {
    Livewire::test(EditContact::class, ['record' => $this->ann->id])
        ->set('data.no_such_key.child', 'x')
        ->assertSuccessful();
});

test('a sub-key of the record of a widget answers 419 and leaves the model untouched', function (string $widget, string $record, string $path) {
    $model = match ($record) {
        'ann' => $this->ann,
        'retainer' => $this->retainer,
        'segment' => $this->segment,
    };

    Livewire::test($widget, ['record' => $model])->set($path, 'x')->assertStatus(419);

    expect($model->fresh()->only(['name', 'title']))->toEqual($model->only(['name', 'title']));
})->with([
    'contact feed name' => [ContactActivityFeed::class, 'ann', 'record.name'],
    'contact feed below the name' => [ContactActivityFeed::class, 'ann', 'record.name.x'],
    'contact details name' => [ContactDetailsWidget::class, 'ann', 'record.name'],
    'deal feed title' => [DealActivityFeed::class, 'retainer', 'record.title'],
    'deal details title' => [DealDetailsWidget::class, 'retainer', 'record.title'],
    'deal invoices title' => [DealInvoicesWidget::class, 'retainer', 'record.title'],
    'segment stats name' => [SegmentStatsOverview::class, 'segment', 'record.name'],
]);

test('a forged record sub-key over raw http answers 419 and a legitimate update answers 200', function () {
    $snapshot = json_encode(Livewire::test(ContactActivityFeed::class, ['record' => $this->ann])->snapshot);

    $send = fn (array $updates) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
    ], ['X-Livewire' => 'true']);

    $send(['record.name' => 'x'])->assertStatus(419);
    $send(['dateFrom' => '2026-10-01'])->assertOk();

    expect($this->ann->fresh()->name)->toBe('Ann Lee');
});

test('the model refusal does not depend on debug mode', function () {
    config(['app.debug' => true]);

    Livewire::test(ContactActivityFeed::class, ['record' => $this->ann])->set('record.name', 'x')->assertStatus(419);
});

test('a model nested in an array property is refused', function () {
    $component = new class extends Component
    {
        public array $items = [];

        public function mount(Contact $contact): void
        {
            $this->items = ['contact' => $contact];
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    Livewire::test($component::class, ['contact' => $this->ann])->set('items.contact.name', 'x')->assertStatus(419);

    expect($this->ann->fresh()->name)->toBe('Ann Lee');
});

test('a non-model object parent is left to its synthesizer, not refused by the guard', function () {
    $component = new class extends Component
    {
        public ?Collection $list = null;

        public function mount(): void
        {
            $this->list = collect(['a' => 'b']);
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    Livewire::test($component::class)->set('list.a', 'c')->assertSuccessful()->assertSet('list', collect(['a' => 'c']));
});

test('a path below a protected property keeps the refusal of Livewire', function () {
    expect(fn () => Livewire::test(ListContacts::class)->set('view.0', 'x'))
        ->toThrow(PublicPropertyNotFoundException::class);
});

test('a path below an uninitialized typed property answers 419', function () {
    $component = new class extends Component
    {
        public string $title;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    Livewire::test($component::class)->set('title.0', 'x')->assertStatus(419);
});
