<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * For an action that works on another model than the one of its page or table, such as logging an activity from a
 * contact: `->authorize(AbilityCheck::for('create', Activity::class))`. The ability is checked on the model, in the
 * organization being worked in.
 */
class AbilityCheck
{
    /**
     * @param  class-string  $model
     * @return Closure(): bool
     */
    public static function for(string $ability, string $model): Closure
    {
        return fn (): bool => Auth::user()?->can($ability, $model) ?? false;
    }
}
