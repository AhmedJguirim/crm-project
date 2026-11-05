<?php

use App\Models\Contact;
use App\Models\LostReason;
use App\Models\OrganizationUser;
use Illuminate\Database\Eloquent\Model;

/**
 * The concrete Eloquent models of `app/Models`, found by scanning the folder (no booted application needed).
 *
 * @return array<int, class-string<Model>>
 */
function massAssignmentModelClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[] = $class;
    }

    return $classes;
}

/**
 * The models that declare `$guarded` in their own code (a pivot inheriting it from the framework is fine).
 *
 * @param  array<int, class-string<Model>>  $classes
 * @param  string  $ownNamespace  a `$guarded` declared by a class in this namespace counts as the app's own
 * @return array<int, string>
 */
function massAssignmentOwnGuardedModels(array $classes, string $ownNamespace = 'App\\'): array
{
    return array_values(array_filter(
        $classes,
        fn (string $class): bool => str_starts_with((new ReflectionProperty($class, 'guarded'))->getDeclaringClass()->getName(), $ownNamespace),
    ));
}

class MassAssignmentGuardedFixture extends Model
{
    protected $guarded = [];
}

/**
 * @param  array<int, class-string<Model>>  $classes
 * @return array<int, string>
 */
function massAssignmentEmptyFillableModels(array $classes): array
{
    return array_values(array_filter(
        $classes,
        fn (string $class): bool => (new ReflectionClass($class))->getDefaultProperties()['fillable'] === [],
    ));
}

test('the scanner finds the app models', function () {
    $classes = massAssignmentModelClasses();

    expect($classes)->toContain(LostReason::class, Contact::class, OrganizationUser::class)
        ->and(count($classes))->toBeGreaterThan(15);
});

test('no app model declares $guarded itself', function () {
    expect(massAssignmentOwnGuardedModels(massAssignmentModelClasses()))
        ->toBe([], 'These models declare $guarded; list their columns in $fillable instead.');
});

test('every app model has an explicit, non-empty $fillable', function () {
    expect(massAssignmentEmptyFillableModels(massAssignmentModelClasses()))
        ->toBe([], 'These models have an empty $fillable; list their mass-assignable columns.');
});

test('a pivot that inherits $guarded from the framework passes', function () {
    $reflection = new ReflectionProperty(OrganizationUser::class, 'guarded');

    expect($reflection->getDeclaringClass()->getName())->toStartWith('Illuminate\\')
        ->and(massAssignmentOwnGuardedModels([OrganizationUser::class]))->toBe([])
        ->and(massAssignmentEmptyFillableModels([OrganizationUser::class]))->toBe([]);
});

test('the scanner flags a model that declares $guarded or has no $fillable', function () {
    expect(massAssignmentOwnGuardedModels([MassAssignmentGuardedFixture::class], 'MassAssignment'))->toBe([MassAssignmentGuardedFixture::class])
        ->and(massAssignmentEmptyFillableModels([MassAssignmentGuardedFixture::class]))->toBe([MassAssignmentGuardedFixture::class]);
});
