<?php

namespace App\Support\Reports;

use App\Enums\Currency;
use App\Support\Tenancy\CurrentOrganization;
use InvalidArgumentException;

/**
 * Builds the SQL that counts an invoice amount in the home currency, each row at its invoice's frozen exchange rate.
 */
final class HomeAmount
{
    /**
     * The amount expressions a conversion accepts.
     */
    private const AMOUNT_EXPRESSIONS = [
        'invoices.amount',
        'invoices.amount_paid',
        'invoices.amount - invoices.amount_paid',
        'invoice_payments.amount',
    ];

    /**
     * The rate of the invoice row: its frozen rate, or 1 when it has none and is in the home currency, otherwise NULL
     * (unknown). It holds one placeholder: the home currency code, see binding().
     */
    public static function rateSql(): string
    {
        return 'COALESCE(invoices.exchange_rate, CASE WHEN invoices.currency = ? THEN 1 END)';
    }

    /**
     * The amount converted to the home currency and rounded to cents, row by row; NULL when the rate is unknown. It
     * holds one placeholder: the home currency code, see binding().
     *
     * @param  string  $amountExpression  one of the known invoice or payment amount expressions
     *
     * @throws InvalidArgumentException when the expression is not a known one
     */
    public static function sql(string $amountExpression): string
    {
        if (! in_array($amountExpression, self::AMOUNT_EXPRESSIONS, true)) {
            throw new InvalidArgumentException('Unknown amount expression for a home-currency conversion.');
        }

        return 'ROUND(('.$amountExpression.') * '.self::rateSql().', 2)';
    }

    /**
     * The value of each placeholder of rateSql() and sql(): the home currency code of the current organization.
     */
    public static function binding(): string
    {
        return CurrentOrganization::find()?->currencyCode() ?? Currency::Usd->value;
    }
}
