<?php

use App\Concerns\PasswordValidationRules;
use App\Actions\Account\DeleteUserAccount;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user, or explain which organizations to transfer or delete first (SEC-27).
     */
    public function deleteUser(Logout $logout, DeleteUserAccount $deleteUserAccount): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $refusal = $deleteUserAccount->handle(Auth::user(), fn () => $logout());

        if ($refusal !== null) {
            $this->reset('password');
            $this->addError('organizations', $refusal);

            return;
        }

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Delete account') }}</flux:heading>
        <flux:subheading>{{ __('Delete your account and your personal workspace') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')" data-test="delete-user-button">
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Your personal workspace and any organization where you are the only member are deleted with all their data. What you created in organizations other people still use stays there. Enter your password to confirm.') }}
                </flux:subheading>
            </div>

            <flux:error name="organizations" />

            <flux:input wire:model="password" :label="__('Password')" type="password" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                    {{ __('Delete account') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
