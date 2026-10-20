<?php

namespace App\Filament\Resources\LostReasons;

use App\Filament\Resources\LostReasons\Pages\ManageLostReasons;
use App\Filament\Resources\LostReasons\Schemas\LostReasonForm;
use App\Filament\Resources\LostReasons\Tables\LostReasonsTable;
use App\Models\LostReason;
use App\Support\Tenancy\CurrentOrganization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class LostReasonResource extends Resource
{
    protected static ?string $model = LostReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandThumbDown;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Lost reasons';

    protected static ?string $modelLabel = 'Lost reason';

    protected static ?string $pluralModelLabel = 'Lost reasons';

    protected static string|UnitEnum|null $navigationGroup = 'Organization';

    protected static ?int $navigationSort = 21;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return LostReasonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LostReasonsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLostReasons::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', CurrentOrganization::get()->id);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->where('organization_id', CurrentOrganization::get()->id)
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
