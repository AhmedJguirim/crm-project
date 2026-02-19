<?php

namespace App\Observers;

use App\Models\Activity;

class ActivityObserver
{
    public function creating(Activity $activity): void
    {
        if (! $activity->organization_id && $activity->contact) {
            $activity->organization_id = $activity->contact->organization_id;
        }
    }
}
