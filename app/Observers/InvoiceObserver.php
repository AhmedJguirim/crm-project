<?php

namespace App\Observers;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\LocalTime;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use LogicException;

class InvoiceObserver
{
    /**
     * Keeps status, amount paid and paid date consistent on every save: a Paid invoice is fully paid, Paid and Partial invoices carry a paid date (today in the organization timezone when empty), any other status has none. It also applies the exchange rate rules.
     *
     * @throws LogicException When the currency or rate of an issued invoice changes, or a foreign-currency invoice is issued without a rate.
     */
    public function saving(Invoice $invoice): void
    {
        $this->resolveOrganization($invoice);
        $this->applyExchangeRateRules($invoice);

        $invoice->amount_paid ??= 0;

        $status = $invoice->status ?? InvoiceStatus::Draft;

        if ($status === InvoiceStatus::Paid) {
            $invoice->amount_paid = $invoice->amount;
        }

        if (in_array($status, [InvoiceStatus::Paid, InvoiceStatus::Partial], true)) {
            $invoice->paid_at ??= LocalTime::todayDate();

            return;
        }

        $invoice->paid_at = null;
    }

    /**
     * Fills the organization, the Draft status, the home currency when none is given and, when empty, the creator (the authenticated user).
     */
    public function creating(Invoice $invoice): void
    {
        $invoice->created_by ??= Auth::id();

        $this->resolveOrganization($invoice);

        $invoice->currency ??= Currency::from($this->homeCurrencyCode($invoice));

        if (! $invoice->status) {
            $invoice->status = InvoiceStatus::Draft;
        }
    }

    /**
     * Takes the organization from the tenant context, then from the contact, when the invoice has none.
     */
    private function resolveOrganization(Invoice $invoice): void
    {
        if (! $invoice->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $invoice->organization_id = $organizationId;
        }

        if (! $invoice->organization_id && $invoice->contact) {
            $invoice->organization_id = $invoice->contact->organization_id;
        }
    }

    /**
     * The currency code of the invoice's own organization (USD when it has none), never the panel tenant's.
     */
    private function homeCurrencyCode(Invoice $invoice): string
    {
        return Organization::query()->find($invoice->organization_id)?->currencyCode() ?? Currency::Usd->value;
    }

    /**
     * An issued invoice keeps its currency and rate. A Draft in the home currency has no rate, an issued one in the home currency has rate 1, and a foreign-currency invoice can't be issued without a rate.
     */
    private function applyExchangeRateRules(Invoice $invoice): void
    {
        $frozen = $invoice->exists && $invoice->getOriginal('status') !== InvoiceStatus::Draft;

        if ($frozen) {
            if ($invoice->isDirty('currency') || $invoice->isDirty('exchange_rate')) {
                throw new LogicException("The currency and exchange rate of an issued invoice can't change.");
            }

            return;
        }

        $home = $this->homeCurrencyCode($invoice);
        $currency = $invoice->currency?->value ?? $home;
        $status = $invoice->status ?? InvoiceStatus::Draft;

        if ($status === InvoiceStatus::Draft) {
            if ($currency === $home) {
                $invoice->exchange_rate = null;
            }

            return;
        }

        if ($status === InvoiceStatus::Cancelled) {
            return;
        }

        if ($currency === $home) {
            $invoice->exchange_rate = '1';

            return;
        }

        if ($invoice->exchange_rate === null) {
            throw new LogicException("An invoice in another currency than the organization's needs an exchange rate before it is issued.");
        }
    }
}
