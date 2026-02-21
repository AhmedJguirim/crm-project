<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
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
}
