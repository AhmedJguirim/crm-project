<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\Organization;
use App\Support\LocalTime;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Assigns invoice numbers: `INV-<local year>-<sequence>`, the sequence zero-padded to at least three digits and
 * growing past 999. The server is the only writer of the number; nothing the browser sends is used.
 */
final class InvoiceNumbers
{
    /**
     * The number the next invoice of the organization would get, without any lock. For the form's default only: it is
     * a display value and never trusted, the saved number is assigned by `withNextNumber()`.
     */
    public static function preview(Organization $organization): string
    {
        return self::nextNumber($organization);
    }

    /**
     * Calls `$create` with the next number of the organization, inside a transaction that first takes a per-organization
     * advisory lock. The number is read after the lock is held, so a second save of the same organization waits for the
     * first to commit and then reads its number. The lock is held until the surrounding transaction commits.
     *
     * @param  Closure(string): Invoice  $create
     */
    public static function withNextNumber(Organization $organization, Closure $create): Invoice
    {
        return DB::transaction(function () use ($organization, $create): Invoice {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['invoice-number:'.$organization->id]);

            return $create(self::nextNumber($organization));
        });
    }

    /**
     * The highest sequence of the current local year of the organization plus one. Numbers off the
     * `INV-<year>-<3 to 18 digits>` pattern (other years, free text) are ignored.
     */
    private static function nextNumber(Organization $organization): string
    {
        $year = LocalTime::now(Organization::validTimezoneOrUtc($organization->timezone))->year;

        $last = Invoice::query()
            ->forOrganization($organization->id)
            ->whereRaw('invoice_number ~ ?', ['^INV-'.(int) $year.'-[0-9]{3,18}$'])
            ->selectRaw('max(cast(substring(invoice_number from 10) as bigint)) as last_sequence')
            ->value('last_sequence');

        return sprintf('INV-%d-%03d', $year, (int) ($last ?? 0) + 1);
    }
}
