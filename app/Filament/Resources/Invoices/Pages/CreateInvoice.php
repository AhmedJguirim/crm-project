<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Invoices\InvoiceNumbers;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    #[Url(as: 'contact')]
    public ?string $prefillContactId = null;

    #[Url(as: 'deal')]
    public ?string $prefillDealId = null;

    /**
     * Forces Draft status, no paid date, and the authenticated user as creator (overriding anything forged into the form data).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        $data['status'] = InvoiceStatus::Draft;
        $data['paid_at'] = null;

        return $data;
    }

    /**
     * Saves the invoice with the number assigned by the server under a per-organization lock; the form never sends one.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $organization = CurrentOrganization::find();

        abort_if($organization === null, 404);

        return InvoiceNumbers::withNextNumber(
            $organization,
            fn (string $number): Invoice => parent::handleRecordCreation([...$data, 'invoice_number' => $number]),
        );
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
