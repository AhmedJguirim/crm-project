<?php

namespace App\Filament\Resources\CustomFields;

use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\CustomFields\Schemas\CustomFieldForm;
use App\Filament\Resources\CustomFields\Tables\CustomFieldsTable;
use App\Models\CustomField;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CustomFieldResource extends Resource
{
    protected static ?string $model = CustomField::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Custom Fields';

    protected static ?string $modelLabel = 'Custom Field';

    protected static ?string $pluralModelLabel = 'Custom Fields';

    protected static string|UnitEnum|null $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 10;

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
        return CustomFieldForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomFieldsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', Filament::getTenant()->id)
            ->orderBy('order');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->where('organization_id', Filament::getTenant()->id)
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomFields::route('/'),
            'create' => CreateCustomField::route('/create'),
            'edit' => EditCustomField::route('/{record}/edit'),
        ];
    }
}
