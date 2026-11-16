<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * See TenantModelPolicy for what each role can do. Members create, edit, send and record payments on invoices;
 * refunds, cancelling and exporting are for admins and the owner, like deleting, and an invoice with payments can't be deleted.
 */
class InvoicePolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'cancel', 'refund', 'export'];

    public function export(User $user): bool
    {
        return $this->can($user, 'export', null);
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->can($user, 'cancel', $invoice);
    }

    public function refund(User $user, Invoice $invoice): bool
    {
        return $this->can($user, 'refund', $invoice);
    }

    public function delete(User $user, Model $record): bool
    {
        return parent::delete($user, $record)
            && ! ($record instanceof Invoice && $record->payments()->exists());
    }
}
