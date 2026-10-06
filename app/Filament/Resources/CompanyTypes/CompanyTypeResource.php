<?php

namespace App\Filament\Resources\CompanyTypes;

use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\CompanyTypes\Schemas\CompanyTypeForm;
use App\Filament\Resources\CompanyTypes\Tables\CompanyTypesTable;
use App\Models\CompanyType;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CompanyTypeResource extends Resource
{
    protected static ?string $model = CompanyType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Company types';

    protected static ?string $modelLabel = 'Company type';

    protected static ?string $pluralModelLabel = 'Company types';

    protected static string|UnitEnum|null $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return CompanyTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyTypes::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', Filament::getTenant()->id);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->where('organization_id', Filament::getTenant()->id)
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
