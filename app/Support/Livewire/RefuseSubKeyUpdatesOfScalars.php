<?php

namespace App\Support\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use ReflectionProperty;

/**
 * Livewire throws a plain exception, answered with a 500 that echoes the current value, when an update path goes below a
 * value that cannot hold children (`tableSearch.0` on a string, a sub-key of a null property). This guard runs before
 * Livewire applies the update and refuses such a forged path with a 419 (BUG-45). A path below an Eloquent model
 * (`record.name`, or a model nested in an array property) is refused with a 419 as well, because the model synthesizer
 * of Livewire throws a plain exception there (BUG-46). A missing or null nested value and any other object parent
 * (a form object, a collection) are left to Livewire and its synthesizer; unknown or non-public properties keep
 * Livewire's own refusal.
 */
class RefuseSubKeyUpdatesOfScalars
{
    public function __invoke(Component $component, string $path, mixed $value): void
    {
        $segments = explode('.', $path);

        if (count($segments) < 2) {
            return;
        }

        $property = array_shift($segments);

        if (! property_exists($component, $property)) {
            return;
        }

        $reflection = new ReflectionProperty($component, $property);

        if (! $reflection->isPublic() || $reflection->isStatic()) {
            return;
        }

        if (! $reflection->isInitialized($component)) {
            abort(419);
        }

        $target = $component->{$property};

        if ($target instanceof Model) {
            abort(419);
        }

        if (! is_array($target) && ! is_object($target)) {
            abort(419);
        }

        foreach (array_slice($segments, 0, -1) as $segment) {
            if (! is_array($target)) {
                return;
            }

            $target = $target[$segment] ?? null;

            if ($target === null) {
                return;
            }

            if ($target instanceof Model) {
                abort(419);
            }

            if (! is_array($target) && ! is_object($target)) {
                abort(419);
            }
        }
    }
}
