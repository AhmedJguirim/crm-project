<?php

namespace App\Observers;

use App\Jobs\ResyncContactSegments;
use App\Models\Activity;

class ActivityObserver
{
    public function creating(Activity $activity): void
    {
        if (! $activity->organization_id && $activity->contact) {
            $activity->organization_id = $activity->contact->organization_id;
        }
    }

    public function saved(Activity $activity): void
    {
        ResyncContactSegments::dispatchForContacts($activity->organization_id, [$activity->contact_id, $activity->getOriginal('contact_id')]);
    }

    public function deleted(Activity $activity): void
    {
        ResyncContactSegments::dispatchForContacts($activity->organization_id, [$activity->contact_id]);
    }
}
