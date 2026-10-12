<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Support\Tenancy\TenantContext;

class ContactObserver
{
    public function creating(Contact $contact): void
    {
        if (! $contact->organization_id && $organizationId = app(TenantContext::class)->id()) {
            $contact->organization_id = $organizationId;
        }
    }

    public function saved(Contact $contact): void
    {
        ResyncContactSegments::dispatchForContacts($contact->organization_id, [$contact->id]);
    }

    public function deleted(Contact $contact): void
    {
        ResyncContactSegments::dispatchForContacts($contact->organization_id, [$contact->id]);
    }
}
