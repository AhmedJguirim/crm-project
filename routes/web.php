<?php

use App\Http\Controllers\Contacts\DownloadFailedImportRowsController;
use App\Http\Controllers\InviteAcceptController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\TagsController;
use App\Http\Middleware\HandleInertiaRequests;
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

// Download failed CSV import rows (authenticated users only)
Route::get('/contacts/import/failed-rows', DownloadFailedImportRowsController::class)
    ->middleware(['auth'])
    ->name('contacts.import.failed-rows');

// Invoice PDF download (authenticated users only, tenant check in controller)
Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)
    ->middleware(['auth'])
    ->name('invoices.pdf');

// Inertia + React section (middleware scoped here to keep Filament/Livewire untouched)
Route::middleware(HandleInertiaRequests::class)
    ->prefix('app')
    ->name('app.')
    ->group(function () {
        Route::inertia('/', 'Welcome', ['appName' => config('app.name')])->name('welcome');

        Route::middleware(['auth', 'can:view,organization'])
            ->prefix('{organization:slug}')
            ->group(function () {
                Route::get('/tags', [TagsController::class, 'index'])->name('tags.index');
            });
    });

require __DIR__.'/settings.php';
