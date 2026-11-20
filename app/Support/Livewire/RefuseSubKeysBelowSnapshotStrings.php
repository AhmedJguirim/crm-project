<?php

namespace App\Support\Livewire;

use Livewire\Component;
use Livewire\Drawer\Utils;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use ReflectionProperty;

/**
 * Livewire reads the browser's update paths through the checksum-verified snapshot data (`getMetaForPath()`) while it
 * hydrates an update, before the `update` hooks run. When such a path goes below a string with a leading-digit key
 * (`tableSearch.0abc`) PHP 8.4 warns "Illegal string offset" and the request ends in a 500 (BUG-86). This guard runs on
 * `hydrate`, ahead of the other hydrate listeners and of `updateProperties()`, and refuses with a 419 any update path
 * that reaches a string with segments left, whatever the segment: no legitimate client sends a sub-key below a string.
 * It walks the snapshot data the same way Livewire does, including the value of a synthesizer tuple (a `Carbon`
 * property), and never looks at the shape of a key, so a digit-led array key (a repeater uuid) keeps working. Each
 * refusal is written to the security log (SEC-21).
 */
final class RefuseSubKeysBelowSnapshotStrings
{
    /**
     * @param  array<string, mixed>  $memo
     */
    public function __invoke(Component $component, array $memo, ComponentContext $context): void
    {
        foreach ($this->pairsFor($component) as [$data, $updates]) {
            foreach (array_keys($updates) as $path) {
                $this->refuseWhenBelowString($component, $data, (string) $path);
            }
        }
    }

    /**
     * The `[data, updates]` pairs of the request entries that carry this component's snapshot and a dotted update key.
     *
     * @return list<array{0: array<array-key, mixed>, 1: array<array-key, mixed>}>
     */
    private function pairsFor(Component $component): array
    {
        $entries = request()->input('components');

        if (! is_array($entries)) {
            return [];
        }

        $pairs = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['snapshot'] ?? null) || ! is_array($entry['updates'] ?? null)) {
                continue;
            }

            if (! $this->hasDottedKey($entry['updates'])) {
                continue;
            }

            $snapshot = json_decode($entry['snapshot'], true);

            if (! is_array($snapshot) || ! is_array($snapshot['memo'] ?? null) || ! is_array($snapshot['data'] ?? null)) {
                continue;
            }

            if (($snapshot['memo']['id'] ?? null) !== $component->getId()) {
                continue;
            }

            $pairs[] = [$snapshot['data'], $entry['updates']];
        }

        return $pairs;
    }

    /**
     * @param  array<array-key, mixed>  $updates
     */
    private function hasDottedKey(array $updates): bool
    {
        foreach (array_keys($updates) as $path) {
            if (str_contains((string) $path, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function refuseWhenBelowString(Component $component, array $data, string $path): void
    {
        if (! str_contains($path, '.')) {
            return;
        }

        $segments = explode('.', $path);
        $raw = $data;

        foreach ($segments as $segment) {
            [$value] = Utils::isSyntheticTuple($raw) ? $raw : [$raw];

            if (is_string($value)) {
                ForgedPayload::refuse('sub_key_update', $component, $this->memberFor($component, $segments[0]));
            }

            if (! is_array($value)) {
                return;
            }

            $raw = $value[$segment] ?? null;
        }
    }

    private function memberFor(Component $component, string $property): ?string
    {
        if (! property_exists($component, $property)) {
            return null;
        }

        $reflection = new ReflectionProperty($component, $property);

        return $reflection->isPublic() && ! $reflection->isStatic() ? $property : null;
    }
}
