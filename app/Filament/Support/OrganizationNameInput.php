<?php

namespace App\Filament\Support;

use App\Models\Organization;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * The name field of the organization forms. A name is unique per user, among the organizations they belong to (what
 * their switcher shows), not across all customers: that would tell anyone whether a company is a customer.
 */
class OrganizationNameInput
{
    public static function make(?Organization $ignored = null): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($ignored): void {
                    $alreadyBelongsToOne = auth()->user()
                        ->organizations()
                        ->when($ignored, fn ($query) => $query->whereKeyNot($ignored->getKey()))
                        ->whereRaw('lower(trim(organizations.name)) = ?', [mb_strtolower(trim((string) $value))])
                        ->exists();

                    if ($alreadyBelongsToOne) {
                        $fail('You already belong to an organization with this name.');
                    }
                },
            ]);
    }
}
