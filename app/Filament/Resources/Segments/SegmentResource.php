<?php

namespace App\Filament\Resources\Segments;

use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Segments\Schemas\SegmentForm;
use App\Filament\Resources\Segments\Schemas\SegmentInfolist;
use App\Filament\Resources\Segments\Tables\SegmentsTable;
use App\Models\Segment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SegmentResource extends Resource
{
    protected static ?string $model = Segment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Audience';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SegmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SegmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SegmentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', Filament::getTenant()->id)
            ->withCount('contacts');
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSegments::route('/'),
            'view' => ViewSegment::route('/{record}'),
            'rules' => SegmentRuleEngine::route('/{record}/rules'),
        ];
    }
}
