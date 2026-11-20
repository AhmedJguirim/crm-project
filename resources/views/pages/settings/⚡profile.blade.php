<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Auth\EmailChanges;
use App\Support\Auth\VerificationMails;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $current_password = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        if (config('fortify.lowercase_usernames')) {
            $this->email = Str::lower($this->email);
        }

        $emailChanges = Str::lower($this->email) !== Str::lower((string) $user->email);

        $rules = $this->profileRules($user->id);

        if ($emailChanges) {
            $rules['current_password'] = $this->currentPasswordRules();
        }

        try {
            $validated = $this->validate($rules);
        } catch (ValidationException $e) {
            $this->reset('current_password');

            throw $e;
        }

        $user->fill(['name' => $validated['name']]);

        if ($emailChanges) {
            $refusal = EmailChanges::request($user, Str::lower($validated['email']));

            if ($refusal !== null) {
                $this->reset('current_password');
                $this->addError('email', $refusal);

                return;
            }

            $user->save();

            $this->email = $user->email;

            Session::flash('email-change-status', 'Check '.$user->pending_email.' for a link to confirm your new email address.');
        } else {
            $user->email = $validated['email'];

            $user->save();
        }

        $this->reset('current_password');

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: filament()->getUrl());

            return;
        }

        $refusal = VerificationMails::send($user);

        if ($refusal !== null) {
            $this->addError('email', $refusal);

            return;
        }

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Send the confirmation link to the pending email address again.
     */
    public function resendEmailChangeLink(): void
    {
        $user = Auth::user();

        $refusal = EmailChanges::resend($user);

        if ($refusal !== null) {
            $this->addError('email', $refusal);

            return;
        }

        if ($user->pending_email !== null) {
            Session::flash('email-change-status', 'A new link has been sent to '.$user->pending_email.'.');
        }
    }

    /**
     * Drop the pending email address; its links stop working.
     */
    public function cancelEmailChange(): void
    {
        $user = Auth::user();

        if ($user->pending_email === null) {
            return;
        }

        EmailChanges::cancel($user);

        Session::flash('email-change-status', 'The email change was cancelled. Your email stays '.$user->email.'.');
    }

    #[Computed]
    public function pendingEmail(): ?string
    {
        return Auth::user()->pending_email;
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->pendingEmail !== null)
                    <flux:text class="mt-4">
                        {{ __('We sent a link to :pending to confirm your new email address. Your email stays :current until you open it.', ['pending' => $this->pendingEmail, 'current' => auth()->user()->email]) }}

                        <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendEmailChangeLink">
                            {{ __('Send the link again') }}
                        </flux:link>
                        <flux:link class="text-sm cursor-pointer" wire:click.prevent="cancelEmailChange">
                            {{ __('Cancel the change') }}
                        </flux:link>
                    </flux:text>
                @endif

                @if (session('email-change-status'))
                    <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                        {{ session('email-change-status') }}
                    </flux:text>
                @endif

                @if (session('email-change-error'))
                    <flux:text class="mt-2 font-medium text-red-600 dark:text-red-400">
                        {{ session('email-change-error') }}
                    </flux:text>
                @endif

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                autocomplete="current-password"
                :description="__('Required only when you change your email.')"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
