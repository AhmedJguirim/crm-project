<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Actions\LogActivityAction;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Models\Contact;
use App\Models\CustomField;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * @method Contact getRecord()
 */
class ViewContact extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ContactResource::class;

    protected string $view = 'filament.resources.contacts.pages.view-contact';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getFooterWidgets(): array
    {
        return [
            ContactDetailsWidget::class,
            ContactActivityFeed::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            QuickTaskAction::makeForContact($this->getRecord()),

            LogActivityAction::forContact(),
            Action::make('customFields')
                ->label('Custom Fields')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->modalHeading('Custom Fields')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->schema(function (): array {
                    $record = $this->getRecord();
                    $fields = $record->customFieldDefinitions()
                        ->filter(fn (CustomField $field): bool => $field->formatValue($record->customFieldValue($field->key)) !== null);

                    if ($fields->isEmpty()) {
                        return [
                            TextEntry::make('empty')
                                ->hiddenLabel()
                                ->state('This contact has no custom field values.'),
                        ];
                    }

                    return $fields
                        ->map(fn (CustomField $field): TextEntry => TextEntry::make("custom_field_{$field->key}")
                            ->label($field->name)
                            ->state($field->formatValue($record->customFieldValue($field->key))))
                        ->all();
                }),

            EditAction::make()
                ->record($this->getRecord()),
        ];
    }
}
