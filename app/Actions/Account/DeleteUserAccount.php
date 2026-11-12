<?php

namespace App\Actions\Account;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\Security\SecurityLog;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a user account without destroying other people's data (SEC-27): the account is refused while the user is the
 * Owner of an organization other people work in; organizations where the user is the only member are deleted with it;
 * memberships elsewhere are removed; rows the user created in organizations others still use stay (the history columns
 * are set to NULL by the database).
 */
class DeleteUserAccount
{
    /**
     * Returns the refusal sentence (nothing is written), or null once the account is deleted. The user's organizations
     * are locked first and the roles and member counts are read after the lock, so an invitee accepting at the same
     * moment either lands before the count (refusal) or after the commit (the organization is gone).
     *
     * The sign-out runs inside the transaction, right before the user row is deleted: logging out saves the user
     * (remember token), which would insert the deleted user again.
     *
     * @param  Closure(): mixed  $signOut
     */
    public function handle(User $user, Closure $signOut): ?string
    {
        return DB::transaction(function () use ($user, $signOut): ?string {
            $organizations = Organization::query()
                ->whereKey($user->organizations()->pluck('organizations.id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $blocking = [];
            $toDelete = [];
            $toLeave = [];

            foreach ($organizations as $organization) {
                $role = DB::table('organization_user')
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->value('role');
                $others = DB::table('organization_user')
                    ->where('organization_id', $organization->id)
                    ->where('user_id', '!=', $user->id)
                    ->count();

                if ($others === 0) {
                    $toDelete[] = $organization;
                } elseif ($role === OrganizationRole::Owner->value) {
                    $blocking[] = $organization;
                } else {
                    $toLeave[] = $organization;
                }
            }

            if ($blocking !== []) {
                SecurityLog::info('account.deletion_refused', $user, ['organizations' => count($blocking)]);

                $names = array_map(fn (Organization $organization): string => $organization->name, $blocking);
                usort($names, strcasecmp(...));

                return 'Transfer or delete these organizations first: '.implode(', ', $names).'.';
            }

            foreach ($toLeave as $organization) {
                $organization->deleteInvitesFor($user);
                $organization->members()->detach($user->id);
            }

            foreach ($toDelete as $organization) {
                $organization->delete();
            }

            $user->notifications()->delete();

            SecurityLog::warning('account.deleted', $user, [
                'deleted_organizations' => count($toDelete),
                'left_organizations' => count($toLeave),
            ]);

            $signOut();

            $user->delete();

            return null;
        });
    }
}
