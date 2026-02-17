<?php

namespace App\Http\Controllers;

use App\Enums\InviteStatus;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InviteAcceptController extends Controller
{
    /**
     * Handle the invitation accept link clicked from the email.
     *
     * Three possible flows:
     * 1. Guest user → store token in session, redirect to registration
     * 2. Logged-in user with matching email → accept immediately
     * 3. Logged-in user with non-matching email → show error
     */
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $invite = OrganizationInvite::where('token', $token)->first();

        // Invalid or tampered token
        if (! $invite) {
            return $this->redirectWithError('This invitation link is invalid.');
        }

        // Already accepted
        if ($invite->status === InviteStatus::Accepted) {
            return $this->redirectWithError('This invitation has already been accepted.');
        }

        // Revoked by the organization admin/owner
        if ($invite->status === InviteStatus::Revoked) {
            return $this->redirectWithError('This invitation has been revoked. Please contact the organization admin for a new invite.');
        }

        // Token is past its expiry date
        if ($invite->isExpired()) {
            // Auto-update status to expired if it was still pending
            if ($invite->status === InviteStatus::Pending) {
                $invite->update(['status' => InviteStatus::Expired]);
            }

            return $this->redirectWithError('This invitation has expired. Please request a new invite from the organization admin.');
        }

        // ── Guest user: store token in session and redirect to registration ──
        if (! auth()->check()) {
            session([
                'invite_token' => $token,
                'invite_email' => $invite->email,
            ]);

            // check if user is already registered with this email
            $user = User::where('email', $invite->email)->first();
            if ($user) {
                return redirect()->to(Filament::getLoginUrl())
                    ->with('status', 'Please login to accept your invitation.');
            }

            return redirect()->to(Filament::getRegistrationUrl())
                ->with('status', 'Please register to accept your invitation.');
        }

        /** @var User $user */
        $user = auth()->user();

        // ── Logged-in user with non-matching email ──
        if ($user->email !== $invite->email) {
            return $this->redirectWithError(
                "This invitation was sent to {$invite->email}, but you are logged in as {$user->email}. Please log out and try again, or use the correct account."
            );
        }

        // ── Logged-in user with matching email: accept immediately ──
        $this->acceptInvite($invite, $user);

        return redirect()->to(Filament::getUrl($invite->organization))
            ->with('status', "Welcome to {$invite->organization->name}!");
    }

    /**
     * Accept the invitation: add user to organization with the invited role,
     * mark the invite as accepted. Wrapped in a transaction for atomicity.
     */
    public function acceptInvite(OrganizationInvite $invite, User $user): void
    {
        DB::transaction(function () use ($invite, $user) {
            // Add user to the organization with the role specified in the invite
            $invite->organization->members()->attach($user->id, [
                'role' => $invite->role->value,
            ]);

            $invite->markAccepted();
        });

        // Send a Filament notification so it appears in the UI
        Notification::make()
            ->success()
            ->title("Welcome to {$invite->organization->name}!")
            ->body("You've joined as {$invite->role->label()}.")
            ->send();
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
            ->body($message)
            ->send();

        // Flash notification to session so it survives the redirect
        session()->flash('filament.notifications', session('filament.notifications', []));

        return redirect()->to(Filament::getLoginUrl());
    }
}
