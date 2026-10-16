<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\PipelineStages\Pages\ManagePipelineStages;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Container\BindingResolutionException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;

use function Livewire\wrap;

class ForgedCallArgumentsUnresolvableProbe
{
    public function __construct(public string $name) {}
}

class ForgedCallArgumentsRequiredMountProbeComponent extends Component
{
    public function mount(int $start): void {}

    public function render(): string
    {
        return '<div></div>';
    }
}

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

    public function bodyResolvesListenerThroughContainer(): void
    {
        app()->call([$this, 'select']);
    }

    public function bodyCallsListenerThroughWrap(): void
    {
        wrap($this)->select();
    }

    public function takesUnresolvableClass(ForgedCallArgumentsUnresolvableProbe $probe): void {}

    public function bodyBuildsUnresolvableClass(): void
    {
        app()->make(ForgedCallArgumentsUnresolvableProbe::class);
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

test('a forged call that omits a required argument answers 419', function (string $component, string $method, array $params) {
    Livewire::test($component)->call($method, ...$params)->assertStatus(419);
})->with([
    'mount action of the contacts' => [ListContacts::class, 'mountAction', []],
    'mount action of the deals' => [ListDeals::class, 'mountAction', []],
    'reorder of the stages' => [ManagePipelineStages::class, 'reorderTable', []],
    'select of the probe' => [ForgedCallArgumentsProbeComponent::class, 'select', []],
    'dispatch with an empty payload' => [ForgedCallArgumentsProbeComponent::class, '__dispatch', ['probe-selected', []]],
    'dispatch with a payload without the id' => [ForgedCallArgumentsProbeComponent::class, '__dispatch', ['probe-selected', ['x' => 1]]],
]);

test('a malformed dispatch answers 419', function (array $params) {
    Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('__dispatch', ...$params)->assertStatus(419);
})->with([
    'string payload' => [['probe-selected', 'x']],
    'null payload' => [['probe-selected', null]],
    'missing payload' => [['probe-selected']],
    'no params' => [[]],
    'array event name' => [[['x'], []]],
    'int event name' => [[5, []]],
    'unknown event name' => [['no-such-event', []]],
]);

test('a forged reorder without arguments changes no stage', function () {
    $pipeline = $this->org->pipelines()->first();
    $names = fn () => $pipeline->stages()->orderBy('position')->pluck('name')->all();
    $before = $names();

    Livewire::test(ManagePipelineStages::class)->call('reorderTable')->assertStatus(419);

    expect($names())->toBe($before);
});

test('the refusal of a missing argument does not depend on debug mode', function () {
    config(['app.debug' => true]);

    Livewire::test(ListContacts::class)->call('mountAction')->assertStatus(419);
});

test('a call with a missing argument over raw http answers 419 and a plain call answers 200', function () {
    $snapshot = json_encode(Livewire::test(ListContacts::class)->snapshot);

    $send = fn (string $method, array $params) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => $params, 'metadata' => []]]]],
    ], ['X-Livewire' => 'true']);

    $send('mountAction', [])->assertStatus(419);
    $send('sortTable', ['name'])->assertOk();
});

test('legitimate dispatches keep working', function () {
    Livewire::test(ForgedCallArgumentsProbeComponent::class)
        ->call('__dispatch', 'probe-selected', ['id' => 5])
        ->assertStatus(200);
    Livewire::test(ForgedCallArgumentsProbeComponent::class)
        ->call('__dispatch', 'probe-selected', [5])
        ->assertStatus(200);
});

test('a missing-dependency error raised below a method body still propagates', function (string $method, string $message) {
    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call($method))
        ->toThrow(BindingResolutionException::class, $message);
})->with([
    'container call of a listener' => ['bodyResolvesListenerThroughContainer', 'Unable to resolve dependency [Parameter #0 [ <required> int $id ]]'],
    'wrap call of a listener' => ['bodyCallsListenerThroughWrap', 'Unable to resolve dependency [Parameter #0 [ <required> int $id ]]'],
    'container build of an unresolvable class' => ['bodyBuildsUnresolvableClass', 'Unresolvable dependency resolving [Parameter #0 [ <required> string $name ]]'],
]);

test('a class parameter the container cannot build is not turned into a 419', function () {
    expect(fn () => Livewire::test(ForgedCallArgumentsProbeComponent::class)->call('takesUnresolvableClass'))
        ->toThrow(BindingResolutionException::class, 'Unresolvable dependency resolving [Parameter #0 [ <required> string $name ]]');
});

test('a mount without its required parameter is not turned into a 419', function () {
    try {
        Livewire::test(ForgedCallArgumentsRequiredMountProbeComponent::class);
    } catch (Throwable $e) {
        $cause = $e->getPrevious() ?? $e;
    }

    expect($cause)->toBeInstanceOf(BindingResolutionException::class)
        ->and($cause->getMessage())->toStartWith('Unable to resolve dependency [Parameter #0 [ <required> int $start ]]');
});
