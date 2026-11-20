<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public bool $taskReminderMails = true;

    /**
     * Load the signed-in user's current preference.
     */
    public function mount(): void
    {
        $this->taskReminderMails = (bool) Auth::user()->task_reminder_mails;
    }

    /**
     * Save the preference of the signed-in user (never a user chosen by the browser).
     */
    public function save(): void
    {
        Auth::user()->forceFill(['task_reminder_mails' => $this->taskReminderMails])->save();

        $this->dispatch('notification-settings-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Notification Settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Email notifications')" :subheading="__('Choose which emails you get from the CRM')">
        <form method="POST" wire:submit="save" class="mt-6 space-y-6">
            <flux:switch
                wire:model="taskReminderMails"
                :label="__('Task reminder emails')"
                :description="__('Due-tomorrow and overdue reminders, and the daily task digest, in every organization you belong to. Notifications in the app are not affected.')"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-notification-settings-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="notification-settings-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
