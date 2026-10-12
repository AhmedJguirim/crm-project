<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * See TenantModelPolicy for what each role can do. Members create, edit, send and mark invoices as paid; cancelling
 * one is for admins and the owner, like deleting it.
 */
class InvoicePolicy extends TenantModelPolicy
{
    protected array $manageAbilities = ['delete', 'restore', 'deleteAny', 'restoreAny', 'cancel'];

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $this->can($user, 'cancel', $invoice);
    }
}
