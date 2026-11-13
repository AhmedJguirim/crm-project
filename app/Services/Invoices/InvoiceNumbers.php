<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\Organization;
use App\Support\LocalTime;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Assigns invoice numbers: `INV-<local year>-<sequence>`, the sequence zero-padded to at least three digits and
 * growing past 999. The server is the only writer of the number; nothing the browser sends is used. Numbers come from
 * a per-organization, per-year counter that only grows, so a number handed out once is never handed out again, even
 * after its invoice is deleted.
 */
final class InvoiceNumbers
{
    /**
     * The number the next invoice of the organization would get, without any lock and without writing the counter. For
     * the form's default only: it is a display value and never trusted, the saved number is assigned by
     * `withNextNumber()`.
     */
    public static function preview(Organization $organization): string
    {
        $year = self::currentYear($organization);

        return self::format($year, self::nextSequence($organization, $year));
    }

    /**
     * Calls `$create` with the next number of the organization, inside a transaction that first takes a per-organization
     * advisory lock. The number is read after the lock is held, so a second save of the same organization waits for the
     * first to commit and then reads its number. Once `$create` returned, the counter of the organization and year is
     * moved to the number just used, in the same transaction: a create that throws leaves no gap. The lock is held until
     * the surrounding transaction commits.
     *
     * @param  Closure(string): Invoice  $create
     */
    public static function withNextNumber(Organization $organization, Closure $create): Invoice
    {
        return DB::transaction(function () use ($organization, $create): Invoice {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['invoice-number:'.$organization->id]);

            $year = self::currentYear($organization);
            $sequence = self::nextSequence($organization, $year);

            $invoice = $create(self::format($year, $sequence));

            InvoiceSequence::query()
                ->forOrganization($organization->id)
                ->updateOrCreate(
                    ['organization_id' => $organization->id, 'year' => $year],
                    ['last_sequence' => $sequence],
                );

            return $invoice;
        });
    }

    /**
     * The local year of the organization, read once per call so the counter row and the number agree.
     */
    private static function currentYear(Organization $organization): int
    {
        return LocalTime::now(Organization::validTimezoneOrUtc($organization->timezone))->year;
    }

    /**
     * The next sequence of the year: one above the larger of the counter and the highest sequence among the invoices
     * that still exist (which covers invoices written without the service). Numbers off the
     * `INV-<year>-<3 to 18 digits>` pattern (other years, free text) are ignored.
     */
    private static function nextSequence(Organization $organization, int $year): int
    {
        $counter = InvoiceSequence::query()
            ->forOrganization($organization->id)
            ->where('year', $year)
            ->value('last_sequence');

        $existing = Invoice::query()
            ->forOrganization($organization->id)
            ->whereRaw('invoice_number ~ ?', ['^INV-'.(int) $year.'-[0-9]{3,18}$'])
            ->selectRaw('max(cast(substring(invoice_number from 10) as bigint)) as last_sequence')
            ->value('last_sequence');

        return max((int) ($counter ?? 0), (int) ($existing ?? 0)) + 1;
    }

    private static function format(int $year, int $sequence): string
    {
        return sprintf('INV-%d-%03d', $year, $sequence);
    }
}
