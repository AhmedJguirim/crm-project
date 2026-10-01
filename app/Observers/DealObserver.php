<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Deal;

class DealObserver
{
    public function saved(Deal $deal): void
    {
        ResyncContactSegments::dispatchForContacts($deal->organization_id, [$deal->contact_id, $deal->getOriginal('contact_id')]);
    }

    public function deleted(Deal $deal): void
    {
        ResyncContactSegments::dispatchForContacts($deal->organization_id, [$deal->contact_id]);
    }
}
