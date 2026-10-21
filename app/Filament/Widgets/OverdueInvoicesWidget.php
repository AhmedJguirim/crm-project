<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Models\Invoice;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The overdue invoices of the organization (see Invoice::scopeOverdue), oldest due date first, with their unpaid
 * balance and the days late. It does not follow the dashboard period and owner filters.
 */
class OverdueInvoicesWidget extends TableWidget
{
    use SanitizesTableSearch;

    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Invoice::query()->overdue()->with('contact')->orderBy('due_at')->orderBy('id'))
            ->heading('Oldest overdue invoices')
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->url(fn (Invoice $record): ?string => InvoiceResource::canView($record)
                        ? InvoiceResource::getUrl('view', ['record' => $record])
                        : null),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('—'),

                TextColumn::make('balance')
                    ->state(fn (Invoice $record): float => (float) $record->amount - (float) $record->amount_paid)
                    ->money(fn (): string => CurrentOrganization::find()?->currencyCode() ?? 'USD'),

                TextColumn::make('due_at')
                    ->label('Due date')
                    ->date('M j, Y'),

                TextColumn::make('days_late')
                    ->label('Days late')
                    ->state(fn (Invoice $record): string => $this->daysLate($record)),
            ])
            ->headerActions([
                Action::make('seeAll')
                    ->label('See all overdue invoices')
                    ->link()
                    ->url(InvoiceResource::getUrl('index').'?tableFilters[overdue][isActive]=1'),
            ])
            ->paginated([5])
            ->emptyStateHeading('No overdue invoices')
            ->emptyStateDescription('Every unpaid invoice is within its due date.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /**
     * Whole days between the due date and the organization's local today: "1 day", "N days", or "—" when the invoice
     * is flagged overdue but its due date is today or later.
     */
    protected function daysLate(Invoice $invoice): string
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', LocalTime::todayDate());
        $days = (int) CarbonImmutable::createFromFormat('!Y-m-d', $invoice->due_at->toDateString())->diffInDays($today);

        return match (true) {
            $days <= 0 => '—',
            $days === 1 => '1 day',
            default => "{$days} days",
        };
    }
}
