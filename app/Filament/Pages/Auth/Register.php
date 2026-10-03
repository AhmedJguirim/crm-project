<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\InviteAcceptController;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

class Register extends BaseRegister
{
    /**
     * Pre-fill the email field if the user arrived via an invitation link.
     * The invite token and email are stored in the session by InviteAcceptController.
     */
    public function mount(): void
    {
        parent::mount();

        // If arriving from an invite link, pre-fill the email field
        if ($inviteEmail = session('invite_email')) {
            $this->form->fill([
                'email' => $inviteEmail,
            ]);
        }
    }

    /**
     * The base form, plus a hidden field the browser fills with its timezone for the personal organization.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                Hidden::make('timezone')
                    ->dehydrated(false)
                    ->extraAttributes(['x-init' => "\$el.value = Intl.DateTimeFormat().resolvedOptions().timeZone; \$el.dispatchEvent(new Event('input', { bubbles: true }))"]),
            ]);
    }

    /**
     * Set onboarding_completed to false for all new users.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeRegister(array $data): array
    {
        $data['onboarding_completed'] = false;

        return $data;
    }

    /**
     * After the user record is created (inside the DB transaction):
     * 1. Create a personal organization for the new user.
     * 2. If there's a pending invite token in session, auto-accept it
     *    so the user is immediately added to the inviting organization.
     */
    protected function afterRegister(): void
    {
        /** @var User $user */
        $user = $this->form->getModelInstance();

        $user->createPersonalOrganization($this->data['timezone'] ?? null);

        // Check if this registration was triggered by an invitation link
        $this->acceptPendingInviteIfExists($user);
    }

    public function register(): ?RegistrationResponse
    {
        $response = parent::register();

        if ($response) {
            Notification::make()
                ->success()
                ->title('Welcome!')
                ->body("Let's get your contacts set up.")
                ->send();
        }

        return $response;
    }

    /**
     * If the session contains an invite_token (set by InviteAcceptController for guests),
     * validate it and accept the invitation for the newly registered user.
     * The token is cleared from session regardless of outcome.
     */
    private function acceptPendingInviteIfExists(User $user): void
    {
        $token = session()->pull('invite_token');
        session()->forget('invite_email');

        if (! $token) {
            return;
        }

        $invite = OrganizationInvite::where('token', $token)->first();

        // Only accept if the invite is still valid and the email matches
        if (! $invite || ! $invite->isValid() || $invite->email !== $user->email) {
            return;
        }

        /** @var InviteAcceptController $controller */
        $controller = app(InviteAcceptController::class);
        $controller->acceptInvite($invite, $user);
    }
}
