<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $status = $data['status'] instanceof InvoiceStatus
            ? $data['status']
            : InvoiceStatus::from($data['status']);

        if ($status === InvoiceStatus::Paid) {
            $data['paid_at'] ??= today();
        }

        if (! in_array($status, [InvoiceStatus::Paid, InvoiceStatus::Partial], true)) {
            $data['paid_at'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
