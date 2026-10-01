<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use Filament\Facades\Filament;

class ContactObserver
{
    public function creating(Contact $contact): void
    {
        if (! $contact->organization_id && Filament::getTenant()) {
            $contact->organization_id = Filament::getTenant()->id;
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
