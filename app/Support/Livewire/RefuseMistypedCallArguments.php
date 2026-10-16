<?php

namespace App\Support\Livewire;

use Illuminate\Container\BoundMethod;
use Illuminate\Container\Container;
use Illuminate\Container\Util;
use Illuminate\Contracts\Container\BindingResolutionException;
use Livewire\Component;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\ImplicitlyBoundMethod;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Livewire\Wrapped;
use Throwable;
use TypeError;

/**
 * Livewire hands the `params` of a client call to the public method as they come. A param that does not match the
 * declared PHP type throws a `TypeError`, a required param that is missing throws a `BindingResolutionException`, and a
 * malformed `__dispatch` (non-array payload, unknown event name) throws a `TypeError`, an `ErrorException` or an
 * `EventHandlerDoesNotExist`. All of them end in a 500 and an error-log entry. This guard answers such a forged call
 * with a 419 (like BUG-45 and BUG-46) on every component, without a per-method fix (BUG-51, BUG-54).
 *
 * `refuseMistypedArgument()` and `refuseMissingArgument()` listen to Livewire's `exception` event and only convert an
 * error that was raised by the argument handling of the called component method itself, during a client call. The same
 * error raised inside a method body, in `mount()`, in another lifecycle hook, or by a container call or `wrap()` made
 * from a body propagates unchanged.
 *
 * `refuseMalformedDispatch()` checks a `__dispatch` call before Livewire unpacks it.
 *
 * `refusePaginationArguments()` covers the untyped pagination methods of Livewire, which forward their argument to a
 * typed callee one level deeper and so cannot be recognised by the first guard. Other untyped public methods that
 * forward a forged value keep their 500.
 */
final class RefuseMistypedCallArguments
{
    private const CONTAINER_CLASSES = [BoundMethod::class, ImplicitlyBoundMethod::class, Util::class, Container::class];

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

        if ($this->isCalledDirectlyByTheBrowser($trace, 1)) {
            abort(419);
        }
    }

    /**
     * Refuses the exception with a 419 when all of these hold, otherwise returns and lets it propagate:
     * 1. it is a `BindingResolutionException`;
     * 2. the target is a Livewire component;
     * 3. the innermost frame is `BoundMethod::addDependencyForCallParameter`, so a required parameter of a called method
     *    has no value (and not `Container::build()` failing to construct a class);
     * 4. the message starts with `Unable to resolve dependency [`;
     * 5. after the container frames that invoked the method, the next frame is `Wrapped::__call` and the one after it is
     *    `HandleComponents::callMethods` or `SupportEvents::call`, like in `refuseMistypedArgument()`.
     */
    public function refuseMissingArgument(object $target, Throwable $e): void
    {
        if (! $e instanceof BindingResolutionException) {
            return;
        }

        if (! $target instanceof Component) {
            return;
        }

        $trace = $e->getTrace();
        $frame0 = $trace[0] ?? [];

        if (($frame0['class'] ?? null) !== BoundMethod::class || ($frame0['function'] ?? null) !== 'addDependencyForCallParameter') {
            return;
        }

        if (! str_starts_with($e->getMessage(), 'Unable to resolve dependency [')) {
            return;
        }

        if ($this->isCalledDirectlyByTheBrowser($trace, 1)) {
            abort(419);
        }
    }

    /**
     * Refuses a `__dispatch` call with a 419 before Livewire unpacks it, when the event name is not a string naming a
     * listener of the component, or the payload is missing or not an array. Payload keys are not inspected.
     *
     * @param  array<int|string, mixed>  $params
     */
    public function refuseMalformedDispatch(Component $component, string $method, array $params): void
    {
        if ($method !== '__dispatch') {
            return;
        }

        $name = $params[0] ?? null;

        if (! is_string($name) || ! is_array($params[1] ?? null)) {
            abort(419);
        }

        if (! in_array($name, SupportEvents::getListenerEventNames($component), true)) {
            abort(419);
        }
    }

    /**
     * Whether the frames from `$from` on are the container frames that invoked the method, then `Wrapped::__call`, then
     * `HandleComponents::callMethods` or `SupportEvents::call`: a client call and not `mount()`, a hook, or a container
     * call or `wrap()` made from a method body.
     *
     * @param  array<int, array<string, mixed>>  $trace
     */
    private function isCalledDirectlyByTheBrowser(array $trace, int $from): bool
    {
        $index = $from;

        while (isset($trace[$index]['class']) && in_array($trace[$index]['class'], self::CONTAINER_CLASSES, true)) {
            $index++;
        }

        if (($trace[$index]['class'] ?? null) !== Wrapped::class || ($trace[$index]['function'] ?? null) !== '__call') {
            return false;
        }

        $caller = $trace[$index + 1] ?? [];

        return ($caller['class'] ?? null) === HandleComponents::class && ($caller['function'] ?? null) === 'callMethods'
            || ($caller['class'] ?? null) === SupportEvents::class && ($caller['function'] ?? null) === 'call';
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
