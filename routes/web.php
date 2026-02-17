<?php

use App\Http\Controllers\InviteAcceptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Invitation acceptance — public route (handles both guest and authenticated users)
Route::get('/invite/accept/{token}', InviteAcceptController::class)
    ->name('invite.accept');

require __DIR__.'/settings.php';
