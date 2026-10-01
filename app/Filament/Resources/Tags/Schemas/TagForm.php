<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Models\Tag;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(Tag::class, 'name', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('organization_id', Filament::getTenant()->id);
                    })
                    ->validationMessages([
                        'unique' => 'A tag with this name already exists. It may be a deleted one: '
                            .'check the "Trashed" filter of the list and restore it instead of creating a new tag.',
                    ])
                    ->helperText('A short, descriptive label for this tag'),

                ColorPicker::make('color')
                    ->helperText('Optional color to visually identify this tag'),
            ]);
    }
}
