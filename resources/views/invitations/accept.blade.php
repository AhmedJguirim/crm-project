<x-layouts::auth :title="__('Accept invitation')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="'Join '.$invite->organization->name"
            :description="$invite->inviter->name.' invited you to join '.$invite->organization->name.' as '.$invite->role->label().'.'"
        />

        <form method="POST" action="{{ route('invite.accept.store', $token) }}" class="flex flex-col gap-6">
            @csrf

            <flux:button variant="primary" type="submit" class="w-full" data-test="accept-invitation-button">
                {{ __('Accept invitation') }}
            </flux:button>
        </form>

        <flux:link :href="\Filament\Facades\Filament::getUrl()" class="text-center">{{ __('Not now') }}</flux:link>
    </div>
</x-layouts::auth>
