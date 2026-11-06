<?php

namespace App\Support\Livewire;

use Livewire\Component;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Stringable;

/**
 * Livewire assigns a top-level update straight to the public property. When a forged request sends an array (or an
 * object) to a property typed with scalars only (`string $email`, `?int $count`, `int|string $key`), PHP throws a
 * `TypeError`: Livewire turns it into a 419 only with `app.debug` off and rethrows it (a 500) with debug on. This guard
 * runs before the assignment and refuses such a value with a 419 in both modes (BUG-68). It leaves to Livewire null and
 * scalar values (PHP coerces them), dotted paths (`RefuseSubKeyUpdatesOfScalars` and the synthesizers), unknown,
 * non-public and static properties (Livewire's own refusal), untyped properties and every type that admits a
 * non-scalar value (`array`, `mixed`, a class, an enum). A `Stringable` object is let through for a type that contains
 * `string`, because PHP assigns it.
 */
final class RefuseMistypedPropertyUpdates
{
    private const SCALAR_TYPES = ['string', 'int', 'float', 'bool', 'true', 'false', 'null'];

    public function __invoke(Component $component, string $path, mixed $value): void
    {
        if (str_contains($path, '.') || $value === null || is_scalar($value)) {
            return;
        }

        if (! property_exists($component, $path)) {
            return;
        }

        $reflection = new ReflectionProperty($component, $path);

        if (! $reflection->isPublic() || $reflection->isStatic()) {
            return;
        }

        $typeNames = $this->scalarTypeNames($reflection->getType());

        if ($typeNames === null) {
            return;
        }

        if ($value instanceof Stringable && in_array('string', $typeNames, true)) {
            return;
        }

        abort(419);
    }

    /**
     * @return list<string>|null the builtin names of the type, or null when the property is untyped or admits a non-scalar value
     */
    private function scalarTypeNames(?ReflectionType $type): ?array
    {
        $members = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        if ($members === []) {
            return null;
        }

        $names = [];

        foreach ($members as $member) {
            if (! $member instanceof ReflectionNamedType || ! $member->isBuiltin() || ! in_array($member->getName(), self::SCALAR_TYPES, true)) {
                return null;
            }

            $names[] = $member->getName();
        }

        return $names;
    }
}
