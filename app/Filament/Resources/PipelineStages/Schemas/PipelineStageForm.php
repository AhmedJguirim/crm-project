<?php

namespace App\Filament\Resources\PipelineStages\Schemas;

use App\Enums\DealStatus;
use App\Models\PipelineStage;
use App\Services\Deals\DefaultPipeline;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PipelineStageForm
{
    private const CLOSED_PROBABILITY_HELP = 'A won stage is always 100% and a lost stage 0%.';

    private const OPEN_PROBABILITY_HELP = 'How likely a deal in this stage is to be won, from 0 to 100%. Forecasts will use it to weight deal values.';

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
                        if (! self::nameIsTaken($record, (string) $value)) {
                            return;
                        }

                        $fail('Another stage of the pipeline already has this name.');
                    }),

                Select::make('color')
                    ->label('Color')
                    ->options(PipelineStage::COLORS)
                    ->required()
                    ->default('gray')
                    ->native(false),

                TextInput::make('probability')
                    ->label('Win probability')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->disabled(fn (?PipelineStage $record): bool => self::isClosed($record))
                    ->helperText(fn (?PipelineStage $record): string => self::isClosed($record) ? self::CLOSED_PROBABILITY_HELP : self::OPEN_PROBABILITY_HELP),
            ]);
    }

    /**
     * Whether another stage of the same pipeline already has this name, ignoring case and surrounding spaces. A new stage
     * is compared with the stages of the organization's pipeline. Compared in PHP so no raw SQL is involved.
     */
    private static function nameIsTaken(?PipelineStage $record, string $name): bool
    {
        $wanted = mb_strtolower(trim($name));

        return PipelineStage::query()
            ->where('pipeline_id', $record?->pipeline_id ?? DefaultPipeline::ensureFor(CurrentOrganization::get())->getKey())
            ->when($record !== null, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->pluck('name')
            ->contains(fn (string $existing): bool => mb_strtolower(trim($existing)) === $wanted);
    }

    private static function isClosed(?PipelineStage $record): bool
    {
        return $record !== null && $record->type !== DealStatus::Open;
    }
}
