<?php

namespace App\Support\Auth;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses an email that already belongs to a user in any case ("Ann@x.com" is "ann@x.com"), optionally ignoring one user (BUG-65).
 */
final class UniqueUserEmail implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = User::query()
            ->whereEmailIgnoringCase($value)
            ->when($this->ignoreUserId !== null, fn (Builder $query): Builder => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
