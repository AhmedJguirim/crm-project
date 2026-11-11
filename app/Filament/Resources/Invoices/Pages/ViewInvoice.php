<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @method Invoice getRecord()
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->authorize('view')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('invoices.pdf', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('markAsSent')
                ->authorize('update')
                ->label('Mark as Sent')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status === InvoiceStatus::Draft)
                ->schema(fn (): array => $this->issuesInForeignCurrency()
                    ? [InvoiceForm::exchangeRateField(fn (): string => '1 '.$this->getRecord()->currency->value.' =')->required()]
                    : [])
                ->fillForm(fn (): array => [
                    'exchange_rate' => Invoice::trimExchangeRate($this->getRecord()->exchange_rate) ?? Invoice::latestExchangeRate($this->getRecord()->currency),
                ])
                ->modalDescription(fn (): ?string => $this->issuesInForeignCurrency()
                    ? 'The exchange rate is frozen once the invoice is sent.'
                    : null)
                ->modalSubmitActionLabel('Mark as Sent')
                ->action(function (array $data): void {
                    $this->getRecord()->update([
                        'status' => InvoiceStatus::Sent,
                    ] + ($this->issuesInForeignCurrency() ? ['exchange_rate' => $data['exchange_rate']] : []));
                }),

            InvoicesTable::recordPaymentAction(),

            InvoicesTable::recordRefundAction(),

            Action::make('cancelInvoice')
                ->authorize('cancel')
                ->label('Cancel Invoice')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancel invoice?')
                ->modalDescription('This will mark the invoice as cancelled.')
                ->modalSubmitActionLabel('Yes, cancel invoice')
                ->visible(fn (): bool => ! in_array($this->getRecord()->status, [InvoiceStatus::Cancelled, InvoiceStatus::Paid], true))
                ->action(function (): void {
                    $this->getRecord()->update([
                        'status' => InvoiceStatus::Cancelled,
                        'paid_at' => null,
                    ]);
                }),

            EditAction::make(),
        ];
    }

    /**
     * Whether the invoice is in another currency than the organization's, so sending it needs an exchange rate.
     */
    private function issuesInForeignCurrency(): bool
    {
        return $this->getRecord()->currency->value !== InvoiceForm::homeCurrencyCode();
    }
}
