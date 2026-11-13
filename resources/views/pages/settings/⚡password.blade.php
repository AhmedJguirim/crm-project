<?php

use App\Actions\Account\EndOtherSessions;
use App\Concerns\PasswordValidationRules;
use App\Enums\AccountSecurityEvent;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityMails;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        $user = Auth::user();

        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            $guard->hashPasswordForCookie($user->getAuthPassword()),
        );

        app(EndOtherSessions::class)->handle($user, session()->getId());

        SecurityLog::info('account.password_changed', $user);

        SecurityMails::send($user, AccountSecurityEvent::PasswordChanged);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Password Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-password-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="password-updated">
                    {{ __('Saved. You are signed out on your other devices.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
