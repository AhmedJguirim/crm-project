<?php

namespace App\Support\Livewire;

use Illuminate\Container\BoundMethod;
use Illuminate\Container\Container;
use Illuminate\Container\Util;
use Livewire\Component;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Livewire\Wrapped;
use Throwable;
use TypeError;

/**
 * Livewire hands the `params` of a client call to the public method as they come. A param that does not match the
 * declared PHP type throws a `TypeError`, which ends in a 500 and an error-log entry. This guard answers such a forged
 * call with a 419 (like BUG-45 and BUG-46) on every component, without a per-method fix (BUG-51).
 *
 * `refuseMistypedArgument()` listens to Livewire's `exception` event and only converts a `TypeError` that was raised by
 * the argument check of the called component method itself, during a client call. A `TypeError` raised inside a method
 * body, in `mount()` or in another lifecycle hook propagates unchanged.
 *
 * `refusePaginationArguments()` covers the untyped pagination methods of Livewire, which forward their argument to a
 * typed callee one level deeper and so cannot be recognised by the first guard. Other untyped public methods that
 * forward a forged value keep their 500.
 */
final class RefuseMistypedCallArguments
{
    private const CONTAINER_CLASSES = [BoundMethod::class, Util::class, Container::class];

    private const PAGINATION_METHODS = ['gotoPage', 'nextPage', 'previousPage', 'resetPage', 'setPage'];

    /**
     * Refuses the exception with a 419 when all of these hold, otherwise returns and lets it propagate:
     * 1. it is a `TypeError`;
     * 2. the target is a Livewire component;
     * 3. the innermost frame is a method of the target's class;
     * 4. the message is the argument check of exactly that method (`Class::method(): Argument #n`);
     * 5. after the container frames (`BoundMethod`, `Util`, `Container`) that invoked the method, the next frame is
     *    `Wrapped::__call`, so the call went through Livewire's wrapper and not through a container call made from a body;
     * 6. the frame right after `Wrapped::__call` is `HandleComponents::callMethods` or `SupportEvents::call`, so it is a
     *    client call (or a forged event payload) and not `mount()`, a hook or a `wrap()` made from a method body.
     */
    public function refuseMistypedArgument(object $target, Throwable $e): void
    {
        if (! $e instanceof TypeError) {
            return;
        }

        if (! $target instanceof Component) {
            return;
        }

        $trace = $e->getTrace();
        $frame0 = $trace[0] ?? [];

        if (! isset($frame0['class'], $frame0['function']) || ! $target instanceof $frame0['class']) {
            return;
        }

        if (! str_starts_with($e->getMessage(), $frame0['class'].'::'.$frame0['function'].'(): Argument #')) {
            return;
        }

        $index = 1;

        while (isset($trace[$index]['class']) && in_array($trace[$index]['class'], self::CONTAINER_CLASSES, true)) {
            $index++;
        }

        if (($trace[$index]['class'] ?? null) !== Wrapped::class || ($trace[$index]['function'] ?? null) !== '__call') {
            return;
        }

        $caller = $trace[$index + 1] ?? [];

        $isClientCall = ($caller['class'] ?? null) === HandleComponents::class && ($caller['function'] ?? null) === 'callMethods'
            || ($caller['class'] ?? null) === SupportEvents::class && ($caller['function'] ?? null) === 'call';

        if ($isClientCall) {
            abort(419);
        }
    }

    /**
     * Refuses a call to one of the untyped pagination methods with a 419 when any param is neither an int nor a string.
     *
     * @param  array<int|string, mixed>  $params
     */
    public function refusePaginationArguments(Component $component, string $method, array $params): void
    {
        if (! in_array($method, self::PAGINATION_METHODS, true)) {
            return;
        }

        foreach ($params as $param) {
            if (! is_int($param) && ! is_string($param)) {
                abort(419);
            }
        }
    }
}
