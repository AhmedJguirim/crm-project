<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\InviteAcceptController;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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
     * The base email field, lower-cased before validation and when stored (when `fortify.lowercase_usernames` is on),
     * so the unique rule sees the stored form and the login form, which lower-cases what is typed, finds the user.
     */
    protected function getEmailFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getEmailFormComponent();

        $lowerCase = fn (?string $state): ?string => is_string($state) && config('fortify.lowercase_usernames') ? Str::lower($state) : $state;

        return $component
            ->mutateStateForValidationUsing($lowerCase)
            ->mutateDehydratedStateUsing($lowerCase);
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
     * If the session contains an invite_id (set by InviteAcceptController for guests),
     * validate the invite and accept the invitation for the newly registered user.
     * The invite id is cleared from session regardless of outcome.
     */
    private function acceptPendingInviteIfExists(User $user): void
    {
        $inviteId = session()->pull('invite_id');
        session()->forget('invite_email');

        if (! $inviteId) {
            return;
        }

        $invite = OrganizationInvite::query()->find($inviteId);

        // Only accept if the invite is still valid and the email matches
        if (! $invite || ! $invite->isValid() || ! $invite->isFor($user)) {
            return;
        }

        /** @var InviteAcceptController $controller */
        $controller = app(InviteAcceptController::class);
        $controller->acceptInvite($invite, $user);
    }
}
