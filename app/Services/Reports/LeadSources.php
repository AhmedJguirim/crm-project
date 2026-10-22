<?php

namespace App\Services\Reports;

use App\Enums\DealStatus;
use App\Enums\LeadSource;
use App\Models\Contact;
use App\Models\Deal;
use App\Services\Invoices\InvoiceLedger;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The contacts created and the deals won in a period, per lead source.
 *
 * New contacts follow the rule of the "New contacts" KPI (live contacts created in the period, every status, owner
 * filter on the contact owner). Won deals follow the rule of the won KPI (status Won and won_at in the period, owner
 * filter on the deal owner, a null value counts as 0). A won deal counts for the current lead source of its contact,
 * even a soft-deleted one; a deal without a contact, a contact without a source and a stored value the LeadSource enum
 * does not know all count as "Unknown source".
 */
final class LeadSources
{
    public const UNKNOWN = 'unknown';

    /**
     * One row per source with new contacts or won deals in the period, the sources with the biggest won value first
     * (then won deals, new contacts, label), "Unknown source" after the named ones, and a "Total" row last. Shares are
     * of the total won value ("—" when it is 0). No rows when there is nothing to show.
     *
     * @return array<string, array{source: string, is_total: bool, new_contacts: int, won_count: int, won_value: float, share: string}>
     */
    public static function forPeriod(ReportPeriod $period, ?int $ownerId): array
    {
        $contactRows = Contact::query()
            ->whereBetween('created_at', $period->bounds())
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->groupBy('lead_source')
            ->select('lead_source')
            ->selectRaw('COUNT(*) AS contacts_count')
            ->toBase()
            ->get();

        $dealRows = Deal::query()
            ->leftJoin('contacts', fn (JoinClause $join) => $join
                ->on('contacts.id', '=', 'deals.contact_id')
                ->on('contacts.organization_id', '=', 'deals.organization_id'))
            ->where('deals.status', DealStatus::Won->value)
            ->whereBetween('deals.won_at', $period->bounds())
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('deals.owner_id', $ownerId))
            ->groupBy('contacts.lead_source')
            ->select('contacts.lead_source')
            ->selectRaw('COUNT(*) AS won_count, COALESCE(SUM(deals.value), 0) AS won_value')
            ->toBase()
            ->get();

        /** @var array<string, array{newContacts: int, wonCount: int, wonCents: int}> $sources */
        $sources = [];

        foreach ($contactRows as $row) {
            $key = self::keyFor($row->lead_source);
            $sources[$key] ??= ['newContacts' => 0, 'wonCount' => 0, 'wonCents' => 0];
            $sources[$key]['newContacts'] += (int) $row->contacts_count;
        }

        foreach ($dealRows as $row) {
            $key = self::keyFor($row->lead_source);
            $sources[$key] ??= ['newContacts' => 0, 'wonCount' => 0, 'wonCents' => 0];
            $sources[$key]['wonCount'] += (int) $row->won_count;
            $sources[$key]['wonCents'] += InvoiceLedger::cents($row->won_value);
        }

        if ($sources === []) {
            return [];
        }

        $unknown = $sources[self::UNKNOWN] ?? null;
        unset($sources[self::UNKNOWN]);

        $keys = array_keys($sources);
        usort($keys, fn (string $a, string $b): int => [$sources[$b]['wonCents'], $sources[$b]['wonCount'], $sources[$b]['newContacts']] <=> [$sources[$a]['wonCents'], $sources[$a]['wonCount'], $sources[$a]['newContacts']]
            ?: strcmp(self::labelFor($a), self::labelFor($b)));

        $totalCents = array_sum(array_column($sources, 'wonCents')) + ($unknown['wonCents'] ?? 0);
        $totals = [
            'newContacts' => array_sum(array_column($sources, 'newContacts')) + ($unknown['newContacts'] ?? 0),
            'wonCount' => array_sum(array_column($sources, 'wonCount')) + ($unknown['wonCount'] ?? 0),
        ];

        $rows = [];

        foreach ($keys as $key) {
            $rows[$key] = self::row(self::labelFor($key), false, $sources[$key]['newContacts'], $sources[$key]['wonCount'], $sources[$key]['wonCents'], $totalCents);
        }

        if ($unknown !== null) {
            $rows[self::UNKNOWN] = self::row('Unknown source', false, $unknown['newContacts'], $unknown['wonCount'], $unknown['wonCents'], $totalCents);
        }

        $rows['total'] = self::row('Total', true, $totals['newContacts'], $totals['wonCount'], $totalCents, $totalCents);

        return $rows;
    }

    private static function keyFor(mixed $stored): string
    {
        return LeadSource::tryFrom((string) $stored)?->value ?? self::UNKNOWN;
    }

    private static function labelFor(string $key): string
    {
        return LeadSource::from($key)->getLabel() ?? $key;
    }

    /**
     * @return array{source: string, is_total: bool, new_contacts: int, won_count: int, won_value: float, share: string}
     */
    private static function row(string $label, bool $isTotal, int $newContacts, int $wonCount, int $wonCents, int $totalCents): array
    {
        return [
            'source' => $label,
            'is_total' => $isTotal,
            'new_contacts' => $newContacts,
            'won_count' => $wonCount,
            'won_value' => $wonCents / 100.0,
            'share' => $totalCents > 0 ? (int) round($wonCents / $totalCents * 100).'%' : '—',
        ];
    }
}
