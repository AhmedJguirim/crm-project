<?php

namespace App\Filament\Resources\PipelineStages;

use App\Filament\Resources\PipelineStages\Pages\ManagePipelineStages;
use App\Filament\Resources\PipelineStages\Schemas\PipelineStageForm;
use App\Filament\Resources\PipelineStages\Tables\PipelineStagesTable;
use App\Models\PipelineStage;
use App\Services\Deals\DefaultPipeline;
use App\Support\Tenancy\CurrentOrganization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PipelineStageResource extends Resource
{
    protected static ?string $model = PipelineStage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Pipeline stages';

    protected static ?string $modelLabel = 'Pipeline stage';

    protected static ?string $pluralModelLabel = 'Pipeline stages';

    protected static string|UnitEnum|null $navigationGroup = 'Organization';

    protected static ?int $navigationSort = 20;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return PipelineStageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PipelineStagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePipelineStages::route('/'),
        ];
    }

    /**
     * The stages of the organization's pipeline. Filtered on the organization and the pipeline ids, not through
     * Pipeline::stages(), which would go through the tenant scope (see DefaultPipeline::stagesOf).
     *
     * @return Builder<PipelineStage>
     */
    public static function getEloquentQuery(): Builder
    {
        return self::ofCurrentPipeline(parent::getEloquentQuery());
    }

    /**
     * @return Builder<PipelineStage>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return self::ofCurrentPipeline(parent::getRecordRouteBindingEloquentQuery());
    }

    /**
     * @param  Builder<PipelineStage>  $query
     * @return Builder<PipelineStage>
     */
    private static function ofCurrentPipeline(Builder $query): Builder
    {
        $organization = CurrentOrganization::get();

        return $query
            ->where('organization_id', $organization->id)
            ->where('pipeline_id', DefaultPipeline::ensureFor($organization)->getKey());
    }
}
