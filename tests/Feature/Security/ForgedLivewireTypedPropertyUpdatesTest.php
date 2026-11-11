<?php

use App\Models\User;
use App\Support\Livewire\RefuseMistypedPropertyUpdates;
use Illuminate\Support\Facades\Hash;
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
    $this->user = User::factory()->create(['name' => 'Ann Lee', 'email' => 'ann@example.com']);
    $this->actingAs($this->user);
});

test('a forged array on a settings property answers 419 and writes nothing', function (string $component, string $property, bool $debug) {
    config(['app.debug' => $debug]);

    Livewire::test($component)->set($property, ['x@example.com'])->assertStatus(419);

    $user = $this->user->fresh();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Ann Lee')
        ->and($user->email)->toBe('ann@example.com')
        ->and(Hash::check('password', $user->password))->toBeTrue();
})->with([
    'profile name, debug on' => ['pages::settings.profile', 'name', true],
    'profile email, debug on' => ['pages::settings.profile', 'email', true],
    'password current_password, debug on' => ['pages::settings.password', 'current_password', true],
    'password password, debug on' => ['pages::settings.password', 'password', true],
    'password confirmation, debug on' => ['pages::settings.password', 'password_confirmation', true],
    'delete form password, debug on' => ['pages::settings.delete-user-form', 'password', true],
    'profile email, debug off' => ['pages::settings.profile', 'email', false],
    'delete form password, debug off' => ['pages::settings.delete-user-form', 'password', false],
]);

test('a forged array over raw http answers 419 and a legitimate update still works', function () {
    config(['app.debug' => true]);

    $snapshot = json_encode(Livewire::test('pages::settings.profile')->snapshot);

    $send = fn (array $updates) => $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => []]],
    ], ['X-Livewire' => 'true']);

    $send(['email' => ['x@example.com']])->assertStatus(419);
    $send(['email' => 'new@example.com'])->assertOk();

    expect($this->user->fresh()->email)->toBe('ann@example.com');
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
