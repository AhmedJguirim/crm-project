<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Invoices</x-slot>

        @if ($invoices->isEmpty())
            <p class="text-sm text-gray-400 dark:text-gray-500">No invoices linked to this deal.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs font-medium uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            <th class="pb-2 pr-4">Invoice #</th>
                            <th class="pb-2 pr-4">Status</th>
                            <th class="pb-2 pr-4 text-right">Amount</th>
                            <th class="pb-2 pr-4">Issued</th>
                            <th class="pb-2">Due</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td class="py-2 pr-4">
                                    <a
                                        href="{{ \App\Filament\Resources\Invoices\InvoiceResource::getUrl('view', ['record' => $invoice]) }}"
                                        class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $invoice->invoice_number }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4">
                                    <x-filament::badge :color="$invoice->status->getColor()" :icon="$invoice->status->getIcon()">
                                        {{ $invoice->status->getLabel() }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-2 pr-4 text-right font-semibold text-gray-900 dark:text-gray-100">
                                    {{ Number::currency((float) $invoice->amount, $invoice->currency ?: 'USD') }}
                                </td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">
                                    {{ $invoice->issued_at?->format('M j, Y') ?? '—' }}
                                </td>
                                <td class="py-2 text-gray-600 dark:text-gray-400">
                                    {{ $invoice->due_at?->format('M j, Y') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @php
                $currency = $invoices->first()->currency ?: 'USD';
                $totalAmount = (float) $invoices->sum('amount');
                $totalPaid = (float) $invoices->sum('amount_paid');
                $balance = $totalAmount - $totalPaid;
            @endphp
            <div class="mt-3 space-y-1 border-t border-gray-100 pt-3 dark:border-gray-700">
                <div class="flex justify-between text-sm">
                    <span class="font-medium text-gray-500 dark:text-gray-400">Total</span>
                    <span class="font-semibold text-gray-900 dark:text-gray-100">
                        {{ Number::currency($totalAmount, $currency) }}
                    </span>
                </div>
                @if ($totalPaid > 0)
                    <div class="flex justify-between text-sm">
                        <span class="font-medium text-gray-500 dark:text-gray-400">Paid</span>
                        <span class="font-medium text-green-600 dark:text-green-400">
                            {{ Number::currency($totalPaid, $currency) }}
                        </span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="font-medium text-gray-500 dark:text-gray-400">Balance</span>
                        <span class="font-semibold {{ $balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                            {{ Number::currency($balance, $currency) }}
                        </span>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
