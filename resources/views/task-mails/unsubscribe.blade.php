@php
    $texts = match ($state) {
        'confirm' => ['Stop task reminder emails', 'You will no longer get due-tomorrow and overdue reminders or the daily task digest by email, in any organization you belong to. Notifications in the app are not affected.'],
        'already-off' => ['Task reminder emails are already off', 'You can turn them back on in Settings → Notifications.'],
        'done' => ['You are unsubscribed', 'You will no longer get task reminder emails. You can turn them back on at any time in Settings → Notifications.'],
        'throttled' => ['Too many requests', $message ?? ''],
        default => ['This link is not valid', 'It may be old or incomplete. Sign in and turn off task reminder emails in Settings → Notifications.'],
    };
@endphp
<x-layouts::auth :title="__($state === 'confirm' ? 'Unsubscribe' : $texts[0])">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="$texts[0]" :description="$texts[1]" />

        @if ($state === 'confirm')
            <form method="POST" action="{{ request()->getRequestUri() }}" class="flex flex-col gap-6">
                <input type="hidden" name="List-Unsubscribe" value="One-Click">

                <flux:button variant="primary" type="submit" class="w-full" data-test="unsubscribe-task-mails-button">
                    {{ __('Unsubscribe') }}
                </flux:button>
            </form>
        @endif

        @if ($state !== 'throttled')
            <flux:link :href="route('notification-settings.edit')" class="text-center">{{ __('Manage email settings') }}</flux:link>
        @endif
    </div>
</x-layouts::auth>
