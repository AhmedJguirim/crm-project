<?php

namespace App\Filament\Resources\Segments\Schemas;

use App\Models\Segment;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SegmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::nameInput(),
            ]);
    }

    public static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->unique(Segment::class, 'name', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                return $rule->where('organization_id', Filament::getTenant()->id);
            })
            ->validationMessages([
                'unique' => 'A segment with this name already exists. It may be a deleted one: '
                    .'check the "Trashed" filter of the list and restore it instead of creating a new segment.',
            ]);
    }
}
