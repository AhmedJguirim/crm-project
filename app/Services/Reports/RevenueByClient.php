<?php

namespace App\Services\Reports;

use App\Enums\InvoicePaymentKind;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Invoices\InvoiceLedger;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * The money collected per client in a period, and what each client still owes today.
 *
 * Collected is the net invoice ledger dated in the period (payments minus refunds, every invoice status, the rule of
 * the "Cash Collected" KPI). Outstanding is the unpaid balance of the Sent, Partial and Overdue invoices as of today.
 * An invoice counts for the company of its deal; without a deal company, for its contact's company when the contact
 * has exactly one live company; otherwise for the contact itself. Soft-deleted companies and contacts keep their row,
 * labelled "(deleted)". The owner filter is the invoice creator.
 */
final class RevenueByClient
{
    public const TOP_CLIENTS = 10;

    /**
     * One row per client with money collected in the period or a balance still owed, the biggest collectors first (then
     * the biggest balance, then the name), cut at TOP_CLIENTS, the rest summed into an "Other clients (N)" row, and a
     * "Total" row last. Shares are of the total collected ("—" when it is not positive). No rows when there is nothing
     * to show.
     *
     * @return array<string, array{client: string, kind: ?string, is_total: bool, collected: float, share: string, outstanding: float}>
     */
    public static function forPeriod(ReportPeriod $period, ?int $ownerId): array
    {
        $collectedRows = InvoicePayment::query()
            ->join('invoices', fn (JoinClause $join) => $join
                ->on('invoices.id', '=', 'invoice_payments.invoice_id')
                ->on('invoices.organization_id', '=', 'invoice_payments.organization_id'))
            ->whereBetween('invoice_payments.paid_on', $period->dates())
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('invoices.created_by', $ownerId))
            ->groupBy('invoices.contact_id', 'invoices.deal_id')
            ->select(['invoices.contact_id', 'invoices.deal_id'])
            ->selectRaw(
                'SUM(CASE WHEN invoice_payments.kind = ? THEN invoice_payments.amount ELSE -invoice_payments.amount END) AS collected',
                [InvoicePaymentKind::Payment->value],
            )
            ->toBase()
            ->get();

        $balanceRows = Invoice::query()
            ->outstanding()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('created_by', $ownerId))
            ->groupBy('contact_id', 'deal_id')
            ->select(['contact_id', 'deal_id'])
            ->selectRaw('SUM(amount - amount_paid) AS balance')
            ->toBase()
            ->get();

        if ($collectedRows->isEmpty() && $balanceRows->isEmpty()) {
            return [];
        }

        $pairs = $collectedRows->concat($balanceRows);
        $resolver = self::resolver($pairs);

        /** @var array<string, array{client: string, kind: string, collected: int, outstanding: int}> $clients */
        $clients = [];

        foreach ($collectedRows as $row) {
            $client = $resolver($row->contact_id, $row->deal_id);
            $clients[$client['key']] ??= ['client' => $client['label'], 'kind' => $client['kind'], 'collected' => 0, 'outstanding' => 0];
            $clients[$client['key']]['collected'] += InvoiceLedger::cents($row->collected);
        }

        foreach ($balanceRows as $row) {
            $client = $resolver($row->contact_id, $row->deal_id);
            $clients[$client['key']] ??= ['client' => $client['label'], 'kind' => $client['kind'], 'collected' => 0, 'outstanding' => 0];
            $clients[$client['key']]['outstanding'] += InvoiceLedger::cents($row->balance);
        }

        $clients = array_filter($clients, fn (array $client): bool => $client['collected'] !== 0 || $client['outstanding'] > 0);

        if ($clients === []) {
            return [];
        }

        uasort($clients, fn (array $a, array $b): int => [$b['collected'], $b['outstanding']] <=> [$a['collected'], $a['outstanding']] ?: strcmp($a['client'], $b['client']));

        $totalCollected = array_sum(array_column($clients, 'collected'));
        $totalOutstanding = array_sum(array_column($clients, 'outstanding'));

        $shown = array_slice($clients, 0, self::TOP_CLIENTS, true);
        $others = array_slice($clients, self::TOP_CLIENTS, null, true);

        $rows = [];

        foreach ($shown as $key => $client) {
            $rows[$key] = self::row($client['client'], $client['kind'], false, $client['collected'], $client['outstanding'], $totalCollected);
        }

        if ($others !== []) {
            $rows['others'] = self::row(
                'Other clients ('.count($others).')',
                null,
                false,
                array_sum(array_column($others, 'collected')),
                array_sum(array_column($others, 'outstanding')),
                $totalCollected,
            );
        }

        $rows['total'] = self::row('Total', null, true, $totalCollected, $totalOutstanding, $totalCollected);

        return $rows;
    }

    /**
     * @return array{client: string, kind: ?string, is_total: bool, collected: float, share: string, outstanding: float}
     */
    private static function row(string $client, ?string $kind, bool $isTotal, int $collectedCents, int $outstandingCents, int $totalCents): array
    {
        return [
            'client' => $client,
            'kind' => $kind,
            'is_total' => $isTotal,
            'collected' => $collectedCents / 100.0,
            'share' => $totalCents > 0 ? (int) round($collectedCents / $totalCents * 100).'%' : '—',
            'outstanding' => $outstandingCents / 100.0,
        ];
    }

    /**
     * Loads, in a constant number of queries, what the attribution rule needs for the given (contact, deal) pairs and
     * returns the function that maps one pair to its client.
     *
     * @param  Collection<int, object>  $pairs  rows with `contact_id` and `deal_id`
     * @return callable(int, ?int): array{key: string, label: string, kind: string}
     */
    private static function resolver(Collection $pairs): callable
    {
        $dealIds = $pairs->pluck('deal_id')->filter()->unique()->values();
        $contactIds = $pairs->pluck('contact_id')->unique()->values();

        $dealCompanyIds = $dealIds->isEmpty()
            ? collect()
            : Deal::query()->whereIn('id', $dealIds)->whereNotNull('company_id')->pluck('company_id', 'id');

        $companies = $dealCompanyIds->isEmpty()
            ? collect()
            : Company::withTrashed()->whereIn('id', $dealCompanyIds->unique()->values())->get(['id', 'name', 'deleted_at'])->keyBy('id');

        $contacts = Contact::withTrashed()->whereIn('id', $contactIds)->with('companies:id,name')->get()->keyBy('id');

        return function (int $contactId, ?int $dealId) use ($dealCompanyIds, $companies, $contacts): array {
            $company = $dealId !== null && $dealCompanyIds->has($dealId) ? $companies->get($dealCompanyIds->get($dealId)) : null;

            if ($company instanceof Company) {
                return [
                    'key' => 'company-'.$company->id,
                    'label' => $company->name.($company->trashed() ? ' (deleted)' : ''),
                    'kind' => 'Company',
                ];
            }

            $contact = $contacts->get($contactId);

            if ($contact instanceof Contact && $contact->companies->count() === 1) {
                $liveCompany = $contact->companies->first();

                return ['key' => 'company-'.$liveCompany->id, 'label' => $liveCompany->name, 'kind' => 'Company'];
            }

            return [
                'key' => 'contact-'.$contactId,
                'label' => ($contact?->name ?? 'Contact #'.$contactId).($contact?->trashed() ? ' (deleted)' : ''),
                'kind' => 'Contact',
            ];
        };
    }
}
