<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Jobs\ProcessContactImportJob;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use App\Services\ContactImportTemplate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        $maximumSize = Number::fileSize(ContactImportFileReader::MAX_UPLOAD_KILOBYTES * 1024);

        return [
            Action::make('downloadTemplate')
                ->label('Download Excel Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()
                    ->download(
                        ContactImportTemplate::forOrganization(Filament::getTenant()->id)->writeXlsx(),
                        'contacts-import-template.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                    )
                    ->deleteFileAfterSend()),

            Action::make('importContacts')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import contacts')
                ->schema([
                    FileUpload::make('file')
                        ->label('File')
                        ->helperText('CSV or Excel (.xlsx). The first row must contain the column headers — use the Excel template for the expected columns. Separate several tags or multi-select values with "'.ContactImportService::MULTI_VALUE_SEPARATOR.'". Write dates as dd-mm-yyyy (or yyyy-mm-dd). Up to '.$maximumSize.'.')
                        ->acceptedFileTypes([
                            'text/csv',
                            'application/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->maxSize(ContactImportFileReader::MAX_UPLOAD_KILOBYTES)
                        ->validationMessages(['max' => "This file is too large: the maximum is {$maximumSize}. Tip: save it as .xlsx, which is much smaller than CSV."])
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
