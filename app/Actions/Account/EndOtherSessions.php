<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the stored sessions of a user, optionally keeping one (SEC-28). Only the database session driver stores the
 * owner of a session (`sessions.user_id`), so with any other driver nothing is deleted: the `AuthenticateSession`
 * middleware still signs those sessions out on their next request, because their stored password hash no longer matches.
 */
class EndOtherSessions
{
    /**
     * Returns the number of session rows deleted. The only scope is the user's id; the kept id is the server's own
     * current session id, never a value from the browser.
     */
    public function handle(User $user, ?string $keepSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $user->getKey())
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
