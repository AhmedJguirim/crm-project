<?php

namespace App\Http\Controllers;

use App\Enums\InviteStatus;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Support\NotificationText;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InviteAcceptController extends Controller
{
    /**
     * Handle the invitation link clicked from the email. A GET never accepts: mail scanners and link prefetchers open links.
     *
     * Three possible flows:
     * 1. Guest user → store the invite id in session, redirect to login when an account exists for the invited email in any case, else to registration
     * 2. Logged-in user with matching email → show the confirmation page (store() accepts)
     * 3. Logged-in user with non-matching email → show error
     */
    public function show(Request $request, string $token): RedirectResponse|View
    {
        $invite = $this->usableInvite($token);

        if ($invite instanceof RedirectResponse) {
            return $invite;
        }

        if (! auth()->check()) {
            session([
                'invite_id' => $invite->id,
                'invite_email' => $invite->email,
            ]);

            $userExists = User::query()->whereRaw('lower(email) = ?', [Str::lower($invite->email)])->exists();

            if ($userExists) {
                return redirect()->to(Filament::getLoginUrl())
                    ->with('status', 'Please login to accept your invitation.');
            }

            return redirect()->to(Filament::getRegistrationUrl())
                ->with('status', 'Please register to accept your invitation.');
        }

        /** @var User $user */
        $user = auth()->user();

        if (! $invite->isFor($user)) {
            return $this->redirectWithMismatch($invite, $user);
        }

        return view('invitations.accept', ['invite' => $invite, 'token' => $token]);
    }

    /**
     * Accept the invitation for the logged-in user whose email matches it (CSRF-protected POST, `auth` middleware).
     * An invitation accepted meanwhile by a concurrent request ends in the "already accepted" error, never a 500.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $invite = $this->usableInvite($token);

        if ($invite instanceof RedirectResponse) {
            return $invite;
        }

        /** @var User $user */
        $user = auth()->user();

        if (! $invite->isFor($user)) {
            return $this->redirectWithMismatch($invite, $user);
        }

        if (! $this->acceptInvite($invite, $user)) {
            return $this->redirectWithError('This invitation has already been accepted.');
        }

        return redirect()->to(Filament::getUrl($invite->organization))
            ->with('status', "Welcome to {$invite->organization->name}!");
    }

    /**
     * Accept the invitation: add user to organization with the invited role, mark the invite as accepted, all in one
     * transaction. The invite row is re-read with a lock, so of two concurrent acceptances only the first one attaches;
     * the other gets false and neither attaches nor notifies.
     */
    public function acceptInvite(OrganizationInvite $invite, User $user): bool
    {
        $accepted = DB::transaction(function () use ($invite, $user): bool {
            $locked = OrganizationInvite::query()->lockForUpdate()->find($invite->getKey());

            if (! $locked || ! $locked->isValid()) {
                return false;
            }

            $locked->organization->members()->attach($user->id, [
                'role' => $locked->role->value,
            ]);

            $locked->markAccepted();

            return true;
        });

        if (! $accepted) {
            return false;
        }

        Notification::make()
            ->success()
            ->title(NotificationText::plain("Welcome to {$invite->organization->name}!"))
            ->body(NotificationText::plain("You've joined as {$invite->role->label()}."))
            ->send();

        return true;
    }

    /**
     * The invitation behind a token, or the error redirect when it is invalid, accepted, revoked or expired (an expired
     * pending one is marked expired).
     */
    private function usableInvite(string $token): OrganizationInvite|RedirectResponse
    {
        $invite = OrganizationInvite::findByToken($token);

        if (! $invite) {
            return $this->redirectWithError('This invitation link is invalid.');
        }

        if ($invite->status === InviteStatus::Accepted) {
            return $this->redirectWithError('This invitation has already been accepted.');
        }

        if ($invite->status === InviteStatus::Revoked) {
            return $this->redirectWithError('This invitation has been revoked. Please contact the organization admin for a new invite.');
        }

        if ($invite->isExpired()) {
            if ($invite->status === InviteStatus::Pending) {
                $invite->update(['status' => InviteStatus::Expired]);
            }

            return $this->redirectWithError('This invitation has expired. Please request a new invite from the organization admin.');
        }

        return $invite;
    }

    /**
     * The error redirect for a logged-in user whose email is not the one the invitation was sent to.
     */
    private function redirectWithMismatch(OrganizationInvite $invite, User $user): RedirectResponse
    {
        return $this->redirectWithError(
            "This invitation was sent to {$invite->email}, but you are logged in as {$user->email}. Please log out and try again, or use the correct account."
        );
    }

    /**
     * Redirect to the login page with an error notification flashed to session.
     * Used for all invalid/expired/revoked/mismatched invite scenarios.
     */
    private function redirectWithError(string $message): RedirectResponse
    {
        Notification::make()
            ->danger()
            ->title('Invitation Error')
            ->body(NotificationText::plain($message))
            ->send();

        // Flash notification to session so it survives the redirect
        session()->flash('filament.notifications', session('filament.notifications', []));

        return redirect()->to(Filament::getLoginUrl());
    }
}
