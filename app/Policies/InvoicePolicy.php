<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * See TenantModelPolicy for what each role can do. Members create, edit and record payments on invoices; the status
 * (sending, cancelling, reopening) is changed by admins, the owner or the invoice's creator; refunds and exporting are
 * for admins and the owner, like deleting, and an invoice with payments can't be deleted.
 */
class InvoicePolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'refund', 'export'];

    public function export(User $user): bool
    {
        return $this->can($user, 'export', null);
    }

    /**
     * Whether the user may change the status of the invoice (send, cancel, reopen): admins and the owner, or a member
     * who created it.
     */
    public function changeStatus(User $user, Invoice $invoice): bool
    {
        $role = $this->roleOf($user, $invoice);

        return $role !== null
            && ($role->canManage()
                || ($role->canEdit() && $invoice->created_by !== null && (int) $invoice->created_by === $user->getKey()));
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->changeStatus($user, $invoice);
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
