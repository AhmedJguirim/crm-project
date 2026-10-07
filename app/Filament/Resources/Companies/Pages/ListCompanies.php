<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Jobs\ProcessCompanyImportJob;
use App\Services\CompanyImportTemplate;
use App\Services\ContactImportFileReader;
use App\Services\Imports\ImportCellParser;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        $maximumSize = Number::fileSize(ContactImportFileReader::MAX_UPLOAD_KILOBYTES * 1024);

        return [
            Action::make('downloadTemplate')
                ->authorize('viewAny')
                ->label('Download Excel Template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()
                    ->download(
                        CompanyImportTemplate::forOrganization(Filament::getTenant()->id)->writeXlsx(),
                        'companies-import-template.xlsx',
                        ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                    )
                    ->deleteFileAfterSend()),

            Action::make('importCompanies')
                ->authorize('import')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import companies')
                ->schema([
                    FileUpload::make('file')
                        ->label('File')
                        ->helperText('CSV or Excel (.xlsx). Comma- or semicolon-separated CSV files are accepted. The first row must contain the column headers — use the Excel template for the expected columns. Separate several multi-select values with "'.ImportCellParser::MULTI_VALUE_SEPARATOR.'". Write dates as dd-mm-yyyy (or yyyy-mm-dd). A company that already exists is not updated. Up to '.$maximumSize.'.')
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
                        ->directory('company-imports')
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

                    ProcessCompanyImportJob::dispatch($path, Filament::getTenant()->id, auth()->id());

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
