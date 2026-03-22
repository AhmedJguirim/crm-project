<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('invoices.pdf', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('markAsSent')
                ->label('Mark as Sent')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status === InvoiceStatus::Draft)
                ->action(function (): void {
                    $this->getRecord()->update([
                        'status' => InvoiceStatus::Sent,
                    ]);
                }),

            Action::make('markAsPaid')
                ->label('Mark as Paid')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true))
                ->action(function (): void {
                    $this->getRecord()->update([
                        'status' => InvoiceStatus::Paid,
                        'paid_at' => $this->getRecord()->paid_at ?? today(),
                    ]);
                }),

            Action::make('cancelInvoice')
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
}
