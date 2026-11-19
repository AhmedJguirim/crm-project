<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Actions\ExportInvoicesAction;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Concerns\ResolvesReadableTableRecordKeys;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use ResolvesReadableTableRecordKeys;
    use SanitizesTableSearch;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ExportInvoicesAction::forList(),
        ];
    }
}
