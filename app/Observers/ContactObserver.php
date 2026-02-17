<?php

namespace App\Observers;

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
}
