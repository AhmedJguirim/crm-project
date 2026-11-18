<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use App\Support\Security\SecurityLog;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

/**
 * @method Invoice getRecord()
 */
class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected ?InvoiceStatus $statusBeforeSave = null;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->using(fn (Invoice $record): bool => InvoicesTable::deleteInvoice($record)),
        ];
    }

    /**
     * Remembers the stored status, so that afterSave() can tell a cancellation from an ordinary save.
     */
    protected function beforeSave(): void
    {
        $this->statusBeforeSave = $this->getRecord()->status;
    }

    /**
     * Writes the security log line when this save moved the invoice to Cancelled.
     */
    protected function afterSave(): void
    {
        if ($this->statusBeforeSave === null || $this->statusBeforeSave === InvoiceStatus::Cancelled) {
            return;
        }

        if ($this->getRecord()->status !== InvoiceStatus::Cancelled) {
            return;
        }

        SecurityLog::warning('invoice.cancelled', auth()->user(), [
            'invoice_id' => $this->getRecord()->getKey(),
            'from_status' => $this->statusBeforeSave->value,
            'source' => 'edit_form',
        ]);
    }
}
