<?php

namespace App\Support\Onboarding;

use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\OrganizationUser;
use App\Models\Task;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Auth;

/**
 * The four first steps of the "Get started" card of the dashboard: the organization has a live contact, deal, task and
 * invoice. Nothing is stored; the steps are counted again on each call.
 */
final readonly class GettingStartedChecklist
{
    public function __construct(
        public bool $hasContact,
        public bool $hasDeal,
        public bool $hasTask,
        public bool $hasInvoice,
    ) {}

    /**
     * The checklist of the signed-in user in the current organization, or null when there is none to show: no signed-in
     * user, no current organization, no membership, a viewer, or a user who dismissed it. One query for the membership,
     * then one `exists()` per step.
     */
    public static function forCurrentUser(): ?self
    {
        $userId = Auth::id();
        $organization = CurrentOrganization::find();

        if ($userId === null || $organization === null) {
            return null;
        }

        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $userId)
            ->first(['role', 'checklist_dismissed_at']);

        if ($membership === null || $membership->checklist_dismissed_at !== null) {
            return null;
        }

        $role = $membership->role instanceof OrganizationRole ? $membership->role : OrganizationRole::tryFrom((string) $membership->role);

        if ($role === null || ! $role->canEdit()) {
            return null;
        }

        return new self(
            hasContact: Contact::query()->exists(),
            hasDeal: Deal::query()->exists(),
            hasTask: Task::query()->exists(),
            hasInvoice: Invoice::query()->exists(),
        );
    }

    public function doneCount(): int
    {
        return (int) $this->hasContact + (int) $this->hasDeal + (int) $this->hasTask + (int) $this->hasInvoice;
    }

    public function isComplete(): bool
    {
        return $this->doneCount() === 4;
    }
}
