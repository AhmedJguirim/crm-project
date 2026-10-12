<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments: only the allowed user IDs, never
     * the organization owners, because the dashboard shows the jobs of every tenant. Emails are not used: anyone
     * can register or edit their profile with an email that is on a list.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user !== null
            && in_array((int) $user->getKey(), config('horizon.allowed_user_ids'), true));
    }
}
