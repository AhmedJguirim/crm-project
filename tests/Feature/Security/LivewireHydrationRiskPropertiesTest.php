<?php

use App\Models\Contact;
use Livewire\Component;
use Livewire\Features\SupportFormObjects\Form as LivewireFormObject;
use Livewire\Form;
use Symfony\Component\Finder\Finder;

enum BugNinetyOneBackedEnum: string
{
    case Red = 'red';
}

enum BugNinetyOnePureEnum
{
    case Fast;
}

class BugNinetyOneForm extends Form {}

/**
 * @return list<string> hits formatted "<class>::$<property> (<type name>)" for every public, non-static property
 *                      typed with a Livewire form object, a date or an enum (their forged updates throw before the update guards run, BUG-91)
 */
function hydrationRiskProperties(ReflectionClass $class): array
{
    $hits = [];

    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->isStatic()) {
            continue;
        }

        $type = $property->getType();

        if ($type === null) {
            continue;
        }

        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($members as $member) {
            if (! $member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $name = $member->getName();

            if (is_a($name, LivewireFormObject::class, true) || is_a($name, DateTimeInterface::class, true) || enum_exists($name)) {
                $hits[] = $class->getName().'::$'.$property->getName().' ('.$name.')';
            }
        }
    }

    return $hits;
}

/**
 * @return list<ReflectionClass<Component>>
 */
function hydrationRiskAppComponents(): array
{
    $classes = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isInstantiable() && $reflection->isSubclassOf(Component::class)) {
            $classes[] = $reflection;
        }
    }

    return $classes;
}

/**
 * @return list<ReflectionClass<Component>>
 */
function hydrationRiskSingleFileComponents(): array
{
    $classes = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        if (! str_contains($file->getContents(), 'new class extends')) {
            continue;
        }

        $classes[] = new ReflectionClass(app('livewire.compiler')->compile($file->getRealPath()));
    }

    return $classes;
}

test('the rule flags public non-static properties typed with a form, a date or an enum', function (Component $component, array $suffixes) {
    $hits = hydrationRiskProperties(new ReflectionClass($component));

    expect($hits)->toHaveCount(count($suffixes));

    foreach ($suffixes as $index => $suffix) {
        expect($hits[$index])->toEndWith($suffix);
    }
})->with([
    'nullable Carbon' => [fn () => new class extends Component
    {
        public ?Carbon\Carbon $due = null;
    }, ['$due (Carbon\Carbon)']],
    'union with CarbonImmutable' => [fn () => new class extends Component
    {
        public Carbon\CarbonImmutable|string|null $at = null;
    }, ['$at (Carbon\CarbonImmutable)']],
    'DateTimeInterface' => [fn () => new class extends Component
    {
        public DateTimeInterface $when;
    }, ['$when (DateTimeInterface)']],
    'backed enum' => [fn () => new class extends Component
    {
        public BugNinetyOneBackedEnum $color;
    }, ['$color (BugNinetyOneBackedEnum)']],
    'pure enum' => [fn () => new class extends Component
    {
        public BugNinetyOnePureEnum $mode;
    }, ['$mode (BugNinetyOnePureEnum)']],
    'form object' => [fn () => new class extends Component
    {
        public BugNinetyOneForm $form;
    }, ['$form (BugNinetyOneForm)']],
    'builtin types' => [fn () => new class extends Component
    {
        public string $title = '';

        public array $data = [];

        public int|string $key = 1;
    }, []],
    'model' => [fn () => new class extends Component
    {
        public ?Contact $contact = null;
    }, []],
    'protected and static' => [fn () => new class extends Component
    {
        protected ?Carbon\Carbon $hidden = null;

        public static ?Carbon\Carbon $shared = null;
    }, []],
    'untyped' => [fn () => new class extends Component
    {
        public $untyped;
    }, []],
]);

test('no component of the app or single-file page has a hydration-risk property', function () {
    $appComponents = hydrationRiskAppComponents();
    $singleFileComponents = hydrationRiskSingleFileComponents();

    expect(count($appComponents))->toBeGreaterThanOrEqual(91);
    expect(count($singleFileComponents))->toBeGreaterThanOrEqual(4);

    $hits = [];

    foreach ([...$appComponents, ...$singleFileComponents] as $component) {
        array_push($hits, ...hydrationRiskProperties($component));
    }

    expect($hits)->toBe([], 'Public Livewire properties typed with a Form, a date or an enum throw on a forged update before the update guards run (BUG-91). Extend RefuseSubKeysBelowSnapshotStrings for them first (report option a), then add the property: '.implode(', ', $hits));
});
