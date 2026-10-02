<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Jobs\ProcessContactImportJob;
use App\Models\CustomField;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Download CSV Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $orgId = Filament::getTenant()->id;

                    $customFields = CustomField::where('organization_id', $orgId)
                        ->orderBy('order')
                        ->get();

                    $headers = ['name', 'email', 'phone', 'tags'];

                    foreach ($customFields as $field) {
                        $headers[] = $field->name;
                    }

                    $exampleRow = ['John Doe', 'john@example.com', '+1234567890', 'VIP,Newsletter'];

                    foreach ($customFields as $field) {
                        $exampleRow[] = match ($field->type) {
                            'date' => '2024-01-15',
                            'number' => '42',
                            'select', 'multiselect' => isset($field->options[0]) ? $field->options[0]['value'] : 'example',
                            default => 'example value',
                        };
                    }

                    $csv = implode(',', $headers)."\n".implode(',', $exampleRow)."\n";

                    return response()->streamDownload(
                        fn () => print ($csv),
                        'contacts-import-template.csv',
                        ['Content-Type' => 'text/csv']
                    );
                }),

            Action::make('importContacts')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import contacts')
                ->schema([
                    FileUpload::make('file')
                        ->label('File')
                        ->helperText('CSV or Excel (.xlsx). The first row must contain the column headers — use the template for the expected columns.')
                        ->acceptedFileTypes([
                            'text/csv',
                            'application/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->maxSize(10240)
                        ->disk('local')
                        ->directory('contact-imports')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = $data['file'];

                    if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt', 'xlsx'], true)) {
                        Storage::disk('local')->delete($path);

                        Notification::make()
                            ->danger()
                            ->title('Unsupported file type')
                            ->body('Upload a CSV or an Excel (.xlsx) file.')
                            ->send();

                        return;
                    }

                    $orgId = Filament::getTenant()->id;
                    $userId = auth()->id();

                    ProcessContactImportJob::dispatch($path, $orgId, $userId);

                    Notification::make()
                        ->success()
                        ->title('Import queued')
                        ->body('Your file is being processed. You will receive a notification when complete.')
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
