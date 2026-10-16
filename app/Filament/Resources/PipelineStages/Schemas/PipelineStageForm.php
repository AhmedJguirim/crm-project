<?php

namespace App\Filament\Resources\PipelineStages\Schemas;

use App\Enums\DealStatus;
use App\Models\PipelineStage;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PipelineStageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(50)
                    ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state))
                    ->rule(fn (?PipelineStage $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null || ! self::nameIsTaken($record, (string) $value)) {
                            return;
                        }

                        $fail('Another stage of the pipeline already has this name.');
                    }),

                Select::make('color')
                    ->label('Color')
                    ->options(PipelineStage::COLORS)
                    ->required()
                    ->native(false),

                TextInput::make('probability')
                    ->label('Probability')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->disabled(fn (?PipelineStage $record): bool => self::isClosed($record))
                    ->helperText(fn (?PipelineStage $record): ?string => self::isClosed($record) ? 'A won stage is always 100% and a lost stage 0%.' : null),
            ]);
    }

    /**
     * Whether another stage of the same pipeline already has this name, ignoring case and surrounding spaces. Compared in
     * PHP so no raw SQL is involved.
     */
    private static function nameIsTaken(PipelineStage $record, string $name): bool
    {
        $wanted = mb_strtolower(trim($name));

        return PipelineStage::query()
            ->where('pipeline_id', $record->pipeline_id)
            ->whereKeyNot($record->getKey())
            ->pluck('name')
            ->contains(fn (string $existing): bool => mb_strtolower(trim($existing)) === $wanted);
    }

    private static function isClosed(?PipelineStage $record): bool
    {
        return $record !== null && $record->type !== DealStatus::Open;
    }
}
