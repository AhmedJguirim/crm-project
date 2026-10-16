<?php

namespace App\Filament\Support;

use App\Filament\Support\StateCasts\ScalarStateCast;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A forged array in a single-value form field is refused with a validation error before the field's other rules and the
 * save (BUG-50). Registered once for every text, textarea, single select, radio and color field of the app.
 */
final class SingleValueFieldState implements ValidationRule
{
    public const MESSAGE = 'The :attribute field must be a single value.';

    /**
     * Adds the guard, ahead of the app's own rules, to every single-value field class. It only applies when the raw
     * state is an array (the state of a Select is cast to null, so the raw state is the one to read); multiple
     * selects keep taking lists. A color picker prints its state in its view, so it also reads an array as empty (ScalarStateCast).
     */
    public static function register(): void
    {
        foreach ([TextInput::class, Textarea::class, Select::class, Radio::class, ColorPicker::class] as $fieldClass) {
            $fieldClass::configureUsing(fn (Field $component): Field => $component->rule(
                ['bail', new self],
                fn (Field $component): bool => is_array($component->getRawState())
                    && ! ($component instanceof Select && $component->isMultiple()),
            ));
        }

        ColorPicker::configureUsing(fn (ColorPicker $component): ColorPicker => $component->stateCast(new ScalarStateCast));
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value)) {
            $fail(self::MESSAGE);
        }
    }
}
