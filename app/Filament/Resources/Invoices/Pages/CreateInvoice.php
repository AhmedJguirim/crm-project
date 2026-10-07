<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\Deal;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\Url;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    #[Url(as: 'contact')]
    public ?string $prefillContactId = null;

    #[Url(as: 'deal')]
    public ?string $prefillDealId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = InvoiceStatus::Draft;
        $data['paid_at'] = null;
        $data['invoice_number'] ??= InvoiceForm::nextInvoiceNumber();

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->prefillDealId) {
            $deal = Deal::find($this->prefillDealId);

            if ($deal) {
                $data['deal_id'] = $deal->id;
                $data['contact_id'] = $deal->contact_id;
                $data['amount'] = $deal->value;
            }
        } elseif ($this->prefillContactId) {
            $data['contact_id'] = (int) $this->prefillContactId;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Invoice created';
    }
}
