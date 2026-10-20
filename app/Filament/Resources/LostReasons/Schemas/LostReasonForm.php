<?php

namespace App\Filament\Resources\LostReasons\Schemas;

use App\Models\LostReason;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LostReasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(100)
                    ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                    ->rule(fn (?LostReason $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if (! self::nameIsTaken(CurrentOrganization::get()->getKey(), (string) $value, $record?->getKey())) {
                            return;
                        }

                        $fail('Another lost reason already has this name.');
                    }),
            ]);
    }

    /**
     * Whether another reason of the organization that is not deleted already has this name, ignoring case and surrounding
     * spaces. Compared in PHP so no raw SQL is involved.
     */
    public static function nameIsTaken(int $organizationId, string $name, ?int $exceptId = null): bool
    {
        $wanted = mb_strtolower(trim($name));

        return LostReason::query()
            ->forOrganization($organizationId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->pluck('name')
            ->contains(fn (string $existing): bool => mb_strtolower(trim($existing)) === $wanted);
    }
}
