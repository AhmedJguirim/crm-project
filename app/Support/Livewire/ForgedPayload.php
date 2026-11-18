<?php

namespace App\Support\Livewire;

use App\Support\Security\SecurityLog;
use Livewire\Component;

/**
 * Refuses a forged Livewire payload with a 419 and writes one capped `request.forged_payload` security log line (SEC-21).
 * The line names the guard, the component and, when it is a real property or method of that component, the member.
 * Nothing the browser sent is ever put in the context.
 */
final class ForgedPayload
{
    public static function refuse(string $guard, ?Component $component, ?string $member = null): never
    {
        SecurityLog::refusal('request.forged_payload', ['guard' => $guard, 'component' => $component?->getName(), 'member' => $member]);

        abort(419);
    }
}
