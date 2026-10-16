<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\PipelineStages\Pages\ManagePipelineStages;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;

use function Livewire\wrap;

class ForgedCallArgumentsProbeComponent extends Component
{
    public array $order = [];

    public function mount(int $start = 0): void {}

    public function reorder(array $order): void
    {
        $this->order = $order;
    }

    #[On('probe-selected')]
    public function select(int $id): void {}

    public function bodyCallsInternalWrongly(): void
    {
        strlen([]);
    }

    public function bodyCallsOwnMethodWrongly(): void
    {
        $this->reorder('x');
    }

    public function bodyCallsClosureThroughContainerWrongly(): void
    {
        app()->call(fn (int $x) => $x, ['x' => 'abc']);
    }

    public function bodyCallsOwnMethodThroughContainerWrongly(): void
    {
        app()->call([$this, 'reorder'], ['order' => 'abc']);
    }

    public function bodyCallsOwnMethodThroughWrapWrongly(): void
    {
        wrap($this)->reorder('abc');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

beforeEach(function () {
    config(['app.debug' => false]);

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann.lee@example.test']);
});

test('a forged call to a typed method answers 419', function (string $component, string $method, array $params) {
    Livewire::test($component)->call($method, ...$params)->assertStatus(419);
})->with([
    'sort of the contacts' => [ListContacts::class, 'sortTable', [['x']]],
    'mount action of the contacts' => [ListContacts::class, 'mountAction', [['x']]],
    'reorder of the stages' => [ManagePipelineStages::class, 'reorderTable', ['abc']],
    'sort of the deals' => [ListDeals::class, 'sortTable', [['x']]],
    'reorder of the probe' => [ForgedCallArgumentsProbeComponent::class, 'reorder', ['abc']],
]);

test('a forged reorder changes no stage', function () {
    $pipeline = $this->org->pipelines()->first();
    $names = fn () => $pipeline->stages()->orderBy('position')->pluck('name')->all();
    $before = $names();

    Livewire::test(ManagePipelineStages::class)->call('reorderTable', 'abc')->assertStatus(419);

    expect($names())->toBe($before);
});

test('a forged pagination call answers 419', function (string $component, string $method, array $params) {
    $parameters = $component === ContactActivityFeed::class ? ['record' => $this->ann] : [];

    Livewire::test($component, $parameters)->call($method, ...$params)->assertStatus(419);
})->with([
    'goto page of the contacts' => [ListContacts::class, 'gotoPage', [['x']]],
    'goto page name of the contacts' => [ListContacts::class, 'gotoPage', [2, ['x']]],
    'goto page of the feed' => [ContactActivityFeed::class, 'gotoPage', [['x']]],
    'next page of the feed' => [ContactActivityFeed::class, 'nextPage', [['x']]],
    'previous page of the feed' => [ContactActivityFeed::class, 'previousPage', [null]],
]);

test('a forged event payload to a typed listener answers 419', function () {
    Livewire::test(ForgedCallArgumentsProbeComponent::class)
        ->call('__dispatch', 'probe-selected', ['id' => ['x']])
        ->assertStatus(419);
});

test('the refusal does not depend on debug mode', function () {
    config(['app.debug' => true]);

    Livewire::test(ListContacts::class)->call('sortTable', ['x'])->assertStatus(419);
});

test('a forged call over raw http answers 419 and the plain call answers 200', function () {
    $snapshot = json_encode(Livewire::test(ListContacts::class)->snapshot);

    $send = fn (array $params) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => 'sortTable', 'params' => $params, 'metadata' => []]]]],
    ], ['X-Livewire' => 'true']);

    $send([['x']])->assertStatus(419);
    $send(['name'])->assertOk();
});

test('legitimate calls keep working', function () {
    Livewire::test(ListContacts::class)->call('sortTable', 'name')->assertStatus(200)->assertSet('tableSort', 'name:asc');
    Livewire::test(ListContacts::class)->call('gotoPage', 2, 'page')->assertStatus(200);
    Livewire::test(ListContacts::class)->call('sortTable', 123)->assertStatus(200);
    Livewire::test(ForgedCallArgumentsProbeComponent::class)
        ->call('reorder', ['3', '1'])
        ->assertStatus(200)
        ->assertSet('order', ['3', '1']);
    Livewire::test(ForgedCallArgumentsProbeComponent::class)
        ->call('__dispatch', 'probe-selected', ['id' => 5])
        ->assertStatus(200);
});

test('genuine type errors inside a method body still propagate', function () {
    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('bodyCallsInternalWrongly'))
        ->toThrow(TypeError::class, 'strlen(): Argument #1 ($string) must be of type string, array given');

    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('bodyCallsOwnMethodWrongly'))
        ->toThrow(TypeError::class, 'ForgedCallArgumentsProbeComponent::reorder(): Argument #1 ($order)');

    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('bodyCallsClosureThroughContainerWrongly'))
        ->toThrow(TypeError::class, 'Argument #1 ($x) must be of type int, string given');

    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('bodyCallsOwnMethodThroughContainerWrongly'))
        ->toThrow(TypeError::class, 'ForgedCallArgumentsProbeComponent::reorder(): Argument #1 ($order)');

    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('bodyCallsOwnMethodThroughWrapWrongly'))
        ->toThrow(TypeError::class, 'ForgedCallArgumentsProbeComponent::reorder(): Argument #1 ($order)');
});

test('a type error in mount is not turned into a 419', function () {
    try {
        Livewire::test(ForgedCallArgumentsProbeComponent::class, ['start' => 'abc']);
    } catch (Throwable $e) {
        $cause = $e->getPrevious() ?? $e;
    }

    expect($cause)->toBeInstanceOf(TypeError::class)
        ->and($cause->getMessage())->toStartWith('ForgedCallArgumentsProbeComponent::mount(): Argument #1 ($start)');
});
