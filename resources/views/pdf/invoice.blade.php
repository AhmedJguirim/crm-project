<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            color: #1a1a1a;
            line-height: 1.5;
        }

        .invoice-container {
            padding: 40px 50px;
        }

        /* Header */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 40px;
        }

        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .org-name {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }

        .invoice-title {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            letter-spacing: -0.5px;
        }

        .invoice-number {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }

        .status-draft { background: #f3f4f6; color: #4b5563; }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-paid { background: #dcfce7; color: #15803d; }
        .status-partial { background: #fef3c7; color: #a16207; }
        .status-overdue { background: #fee2e2; color: #b91c1c; }
        .status-cancelled { background: #f3f4f6; color: #6b7280; }

        /* Info sections */
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 32px;
        }

        .info-col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .info-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #9ca3af;
            margin-bottom: 6px;
        }

        .info-value {
            font-size: 13px;
            color: #374151;
        }

        .info-value strong {
            color: #111827;
        }

        /* Dates row */
        .dates-row {
            display: table;
            width: 100%;
            margin-bottom: 32px;
            padding: 16px 20px;
            background: #f9fafb;
            border-radius: 6px;
        }

        .date-col {
            display: table-cell;
            width: 33.33%;
            vertical-align: top;
        }

        /* Amount table */
        .amount-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 32px;
        }

        .amount-table thead th {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #9ca3af;
            padding: 10px 16px;
            border-bottom: 2px solid #e5e7eb;
            text-align: left;
        }

        .amount-table thead th:last-child {
            text-align: right;
        }

        .amount-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }

        .amount-table tbody td:last-child {
            text-align: right;
        }

        .amount-table tfoot td {
            padding: 14px 16px;
            font-weight: 700;
            border-top: 2px solid #111827;
        }

        .amount-table tfoot td:last-child {
            text-align: right;
            font-size: 18px;
            color: #111827;
        }

        /* Notes */
        .notes-section {
            margin-top: 20px;
            padding: 16px 20px;
            background: #f9fafb;
            border-radius: 6px;
        }

        .notes-section .info-label {
            margin-bottom: 8px;
        }

        .notes-text {
            font-size: 12px;
            color: #6b7280;
            white-space: pre-line;
        }

        /* Footer */
        .footer {
            margin-top: 48px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        {{-- Header --}}
        <div class="header">
            <div class="header-left">
                <div class="org-name">{{ $organization->name }}</div>
            </div>
            <div class="header-right">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                <div>
                    <span class="status-badge status-{{ $invoice->status->value }}">
                        {{ $invoice->status->getLabel() }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Bill To / Deal --}}
        <div class="info-row">
            <div class="info-col">
                <div class="info-label">Bill To</div>
                <div class="info-value">
                    <strong>{{ $invoice->contact->name }}</strong><br>
                    @if ($invoice->contact->email)
                        {{ $invoice->contact->email }}<br>
                    @endif
                    @if ($invoice->contact->phone)
                        {{ $invoice->contact->phone }}
                    @endif
                </div>
            </div>
            <div class="info-col">
                @if ($invoice->deal)
                    <div class="info-label">Related Deal</div>
                    <div class="info-value">
                        <strong>{{ $invoice->deal->title }}</strong><br>
                        <span style="color: #6b7280;">{{ $invoice->deal->stage->getLabel() }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Dates --}}
        <div class="dates-row">
            <div class="date-col">
                <div class="info-label">Issue Date</div>
                <div class="info-value"><strong>{{ $invoice->issued_at?->format('M j, Y') ?? '—' }}</strong></div>
            </div>
            <div class="date-col">
                <div class="info-label">Due Date</div>
                <div class="info-value"><strong>{{ $invoice->due_at?->format('M j, Y') ?? '—' }}</strong></div>
            </div>
            <div class="date-col">
                <div class="info-label">Payment Terms</div>
                <div class="info-value"><strong>{{ $invoice->payment_terms === 0 ? 'Due on Receipt' : 'Net ' . $invoice->payment_terms }}</strong></div>
            </div>
        </div>

        {{-- Amount --}}
        <table class="amount-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        {{ $invoice->deal?->title ?? 'Invoice ' . $invoice->invoice_number }}
                    </td>
                    <td>{{ Number::currency((float) $invoice->amount, $organization->currencyCode()) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td>Subtotal</td>
                    <td>{{ Number::currency((float) $invoice->amount, $organization->currencyCode()) }}</td>
                </tr>
                @if ((float) $invoice->amount_paid > 0)
                    <tr>
                        <td style="border-top: none; color: #15803d;">Amount Paid</td>
                        <td style="border-top: none; font-size: 14px; color: #15803d;">- {{ Number::currency((float) $invoice->amount_paid, $organization->currencyCode()) }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="{{ (float) $invoice->amount_paid > 0 ? 'border-top: 2px solid #111827;' : 'border-top: none;' }}">Balance Due</td>
                    <td style="{{ (float) $invoice->amount_paid > 0 ? 'border-top: 2px solid #111827;' : 'border-top: none;' }}">{{ Number::currency((float) $invoice->amount - (float) $invoice->amount_paid, $organization->currencyCode()) }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- Notes --}}
        @if (filled($invoice->notes))
            <div class="notes-section">
                <div class="info-label">Notes</div>
                <div class="notes-text">{{ $invoice->notes }}</div>
            </div>
        @endif

        {{-- Footer --}}
        <div class="footer">
            {{ $organization->name }} &middot; {{ $invoice->invoice_number }}
        </div>
    </div>
</body>
</html>
