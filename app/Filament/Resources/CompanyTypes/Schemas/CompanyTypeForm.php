<?php

namespace App\Filament\Resources\CompanyTypes\Schemas;

use App\Models\CompanyType;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CompanyTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                static::nameInput(),
            ]);
    }

    /**
     * The name of a company type: unique within the organization among the types that are not deleted, ignoring case. Shared
     * by this resource and by the inline "create" of the company form.
     */
    public static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->unique(CompanyType::class, 'name', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule
                ->where('organization_id', Filament::getTenant()?->getKey())
                ->whereNull('deleted_at'))
            ->rule(fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                $taken = CompanyType::query()
                    ->where('organization_id', Filament::getTenant()?->getKey())
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->exists();

                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => 'name']));
                }
            });
    }
}
