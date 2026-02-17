<?php

namespace App\Filament\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        Notification::make()
            ->success()
            ->title(__('Logged out successfully'))
            ->send();

        return redirect()->to(
            Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl(),
        );
    }
}
