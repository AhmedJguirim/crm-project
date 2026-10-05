<?php

namespace App\Filament\Resources\CompanyCustomFields;

use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CompanyCustomFields\Schemas\CompanyCustomFieldForm;
use App\Filament\Resources\CompanyCustomFields\Tables\CompanyCustomFieldsTable;
use App\Models\CompanyCustomField;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CompanyCustomFieldResource extends Resource
{
    protected static ?string $model = CompanyCustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Company fields';

    protected static ?string $modelLabel = 'Company field';

    protected static ?string $pluralModelLabel = 'Company fields';

    protected static string|UnitEnum|null $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 11;

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record) {
            return null;
        }

        $name = $record->name;

        if (strlen($name) > 50) {
            return substr($name, 0, 50).'...';
        }

        return $name;
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyCustomFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyCustomFieldsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('order');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyCustomFields::route('/'),
            'create' => CreateCompanyCustomField::route('/create'),
            'edit' => EditCompanyCustomField::route('/{record}/edit'),
        ];
    }
}
