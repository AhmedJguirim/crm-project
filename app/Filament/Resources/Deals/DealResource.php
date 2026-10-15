<?php

namespace App\Filament\Resources\Deals;

use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Deals\Tables\DealsTable;
use App\Models\Deal;
use App\Support\Tenancy\CurrentOrganization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealResource extends Resource
{
    protected static ?string $model = Deal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'contact.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return DealForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DealInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', CurrentOrganization::get()->id)
            ->with(['contact', 'creator']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeals::route('/'),
            'pipeline' => DealPipeline::route('/pipeline'),
            'create' => CreateDeal::route('/create'),
            'view' => ViewDeal::route('/{record}'),
            'edit' => EditDeal::route('/{record}/edit'),
        ];
    }
}
