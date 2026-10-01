<?php

namespace App\Filament\Resources\Segments\Schemas;

use App\Models\Segment;
use App\Services\Segments\SegmentConditionDescriber;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class SegmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('status')
                            ->state(fn (Segment $record) => $record->status())
                            ->badge(),

                        TextEntry::make('last_synced_at')
                            ->label('Last synced')
                            ->since()
                            ->placeholder('Never'),

                        TextEntry::make('updated_at')
                            ->label('Last modified')
                            ->since(),

                        TextEntry::make('rules')
                            ->label('Published rules')
                            ->columnSpanFull()
                            ->state(fn (Segment $record): HtmlString => self::describeRules($record))
                            ->html(),
                    ]),
            ]);
    }

    private static function describeRules(Segment $record): HtmlString
    {
        $rules = $record->publishedRules();

        if ($rules->isEmpty()) {
            return new HtmlString('<span class="text-gray-500">No published rules yet.</span>');
        }

        $describer = new SegmentConditionDescriber($record->fieldCatalog());

        $html = $rules->map(function ($rule) use ($describer): string {
            $conditions = collect($rule->conditions)
                ->map(fn ($condition): string => '<li>'.$describer->describe($condition)->toHtml().'</li>')
                ->join('<li class="text-gray-500 list-none">and</li>');

            return '<div><p class="font-medium">'.e($rule->name).'</p><ul class="ms-4 list-disc">'.$conditions.'</ul></div>';
        })->join('<p class="my-2 text-sm font-semibold uppercase text-gray-500">or</p>');

        return new HtmlString($html);
    }
}
