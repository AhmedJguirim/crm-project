<?php

use App\Http\Controllers\Auth\ConfirmEmailChangeController;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings/email/confirm/{id}/{hash}', ConfirmEmailChangeController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->where('id', '[0-9]{1,18}')
        ->where('hash', '[0-9a-f]{40}')
        ->name('user-email.confirm');
});

Route::get('settings/{path?}', fn (): RedirectResponse => redirect()->to(Filament::getProfileUrl()))
    ->where('path', '(?!email/confirm(?:/|$)).*')
    ->name('settings.redirect');
