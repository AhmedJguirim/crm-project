<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\Contact;
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

    /**
     * The create page never calls mutateFormDataBeforeFill(), so the ?deal= / ?contact= prefill is applied here.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $prefill = $this->prefillData();

        if ($prefill === []) {
            return;
        }

        $this->form->fillPartially($prefill, array_keys($prefill));
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillData(): array
    {
        $dealId = $this->positiveIntOrNull($this->prefillDealId);

        if ($dealId !== null) {
            $deal = Deal::query()->with('contact')->find($dealId);

            if ($deal?->contact !== null) {
                return [
                    'contact_id' => $deal->contact_id,
                    'deal_id' => $deal->id,
                    'amount' => $deal->value,
                ];
            }
        }

        $contactId = $this->positiveIntOrNull($this->prefillContactId);

        if ($contactId === null) {
            return [];
        }

        $contact = Contact::query()->find($contactId);

        if ($contact === null) {
            return [];
        }

        return ['contact_id' => $contact->id];
    }

    private function positiveIntOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
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
