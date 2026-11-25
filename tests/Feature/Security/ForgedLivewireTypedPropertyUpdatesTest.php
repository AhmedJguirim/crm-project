<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Models\Contact;
use App\Models\User;
use App\Support\Livewire\RefuseMistypedPropertyUpdates;
use Filament\Facades\Filament;
use Livewire\Component;
use Livewire\Exceptions\PropertyNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ForgedTypedPropertiesProbeComponent extends Component
{
    public string $label = '';

    public ?int $count = null;

    public float $ratio = 0.5;

    public bool $flag = false;

    public int|string $key = 0;

    public array $items = [];

    public mixed $anything = null;

    public $untyped = null;

    protected string $hidden = '';

    public static string $shared = '';

    public function render(): string
    {
        return '<div></div>';
    }
}

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann.lee@example.test']);
});

test('a forged array on a scalar-typed property of a real component answers 419', function (string $component, string $property, bool $debug) {
    config(['app.debug' => $debug]);

    Livewire::test($component, $component === ContactActivityFeed::class ? ['record' => $this->ann] : [])
        ->set($property, ['x@example.com'])
        ->assertStatus(419);

    expect($this->ann->fresh()->only(['name', 'email']))->toBe(['name' => 'Ann Lee', 'email' => 'ann.lee@example.test']);
})->with([
    'contact feed dateFrom, debug on' => [ContactActivityFeed::class, 'dateFrom', true],
    'contact feed dateTo, debug on' => [ContactActivityFeed::class, 'dateTo', true],
    'contacts list activeTab, debug on' => [ListContacts::class, 'activeTab', true],
    'contacts list tableSort, debug on' => [ListContacts::class, 'tableSort', true],
    'contacts list tableGrouping, debug on' => [ListContacts::class, 'tableGrouping', true],
    'contacts list isTableReordering, debug on' => [ListContacts::class, 'isTableReordering', true],
    'contact feed dateFrom, debug off' => [ContactActivityFeed::class, 'dateFrom', false],
    'contacts list activeTab, debug off' => [ListContacts::class, 'activeTab', false],
]);

test('a forged array over raw http answers 419 and a legitimate update still works', function () {
    config(['app.debug' => true]);

    $snapshot = json_encode(Livewire::test(ContactActivityFeed::class, ['record' => $this->ann])->snapshot);

    $send = fn (array $updates) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
    ], ['X-Livewire' => 'true']);

    $send(['dateFrom' => ['2026-10-01']])->assertStatus(419);

    $response = $send(['dateFrom' => '2026-10-01']);
    $response->assertOk();

    expect(json_decode($response->json('components.0.snapshot'), true)['data']['dateFrom'])->toBe('2026-10-01')
        ->and($this->ann->fresh()->only(['name', 'email']))->toBe(['name' => 'Ann Lee', 'email' => 'ann.lee@example.test']);
});

test('a forged array on a scalar-typed probe property answers 419', function (string $property) {
    config(['app.debug' => true]);

    Livewire::test(ForgedTypedPropertiesProbeComponent::class)->set($property, [1])->assertStatus(419);
})->with(['string' => 'label', '?int' => 'count', 'float' => 'ratio', 'bool' => 'flag', 'int|string' => 'key']);

test('a property that admits an array, or no type, or a scalar value keeps working', function (string $property, mixed $value, mixed $expected) {
    config(['app.debug' => true]);

    Livewire::test(ForgedTypedPropertiesProbeComponent::class)
        ->set($property, $value)
        ->assertSuccessful()
        ->assertSet($property, $expected);
})->with([
    'array' => ['items', ['a'], ['a']],
    'mixed' => ['anything', ['a'], ['a']],
    'untyped' => ['untyped', ['a'], ['a']],
    'string' => ['label', 'Acme', 'Acme'],
    '?int from a numeric string' => ['count', '5', 5],
]);

test('a null value is left to Livewire', function (string $property) {
    config(['app.debug' => true]);

    Livewire::test(ForgedTypedPropertiesProbeComponent::class)->set($property, null)->assertSuccessful();
})->with(['string' => 'label', '?int' => 'count']);

test('a protected property keeps the refusal of Livewire and a static one is left alone by the guard', function () {
    config(['app.debug' => true]);

    expect(fn () => Livewire::test(ForgedTypedPropertiesProbeComponent::class)->set('hidden', ['x']))
        ->toThrow(PropertyNotFoundException::class);

    (new RefuseMistypedPropertyUpdates)(new ForgedTypedPropertiesProbeComponent, 'shared', ['x']);
});

test('an unknown property keeps the refusal of Livewire', function () {
    config(['app.debug' => true]);

    expect(fn () => Livewire::test(ForgedTypedPropertiesProbeComponent::class)->set('missing', ['x']))
        ->toThrow(PublicPropertyNotFoundException::class);
});

test('a Stringable object is let through for a string type only, and dotted paths are left alone', function () {
    $component = new ForgedTypedPropertiesProbeComponent;
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'Acme';
        }
    };
    $guard = new RefuseMistypedPropertyUpdates;

    $guard($component, 'label', $stringable);
    $guard($component, 'label.0', ['x']);

    try {
        $guard($component, 'count', $stringable);
        $this->fail('The guard let a Stringable through to an int property.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(419);
    }
});
