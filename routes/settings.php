<?php

use App\Http\Controllers\Auth\ConfirmEmailChangeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');

    Route::get('settings/email/confirm/{id}/{hash}', ConfirmEmailChangeController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->where('id', '[0-9]{1,18}')
        ->where('hash', '[0-9a-f]{40}')
        ->name('user-email.confirm');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/password', 'pages::settings.password')->name('user-password.edit');
    Route::livewire('settings/notifications', 'pages::settings.notifications')->name('notification-settings.edit');
});
