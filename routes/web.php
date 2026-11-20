<?php

use App\Http\Controllers\Contacts\DownloadFailedImportRowsController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DownloadExportController;
use App\Http\Controllers\InviteAcceptController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\RobotsTxtController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TagsController;
use App\Http\Middleware\HandleInertiaRequests;
use Filament\Facades\Filament;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::view('/', 'landing')->name('home');

// SEC-02: one sign-in door. Fortify's password-only login is replaced by the panel login, which asks for the second factor.
Route::get('login', fn (): RedirectResponse => redirect()->to(Filament::getLoginUrl()))
    ->middleware('guest')
    ->name('login');
Route::post('login', fn (): RedirectResponse => redirect()->to(Filament::getLoginUrl(), 303))
    ->name('login.store');

// SEC-69: CSP violation reports (report-only). No CSRF token or session: browsers post them bare. Throttled, logged without URLs.
Route::post('csp-report', CspReportController::class)
    ->withoutMiddleware([ValidateCsrfToken::class, StartSession::class, ShareErrorsFromSession::class, AuthenticateSession::class, AddQueuedCookiesToResponse::class])
    ->middleware('throttle:csp-reports')
    ->name('csp.report');

Route::get('robots.txt', RobotsTxtController::class)->name('robots');
Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('privacy', [LegalPageController::class, 'privacy'])->name('privacy');
Route::get('terms', [LegalPageController::class, 'terms'])->name('terms');

// Invitation acceptance — public route (handles both guest and authenticated users)
Route::get('/invite/accept/{token}', [InviteAcceptController::class, 'show'])
    ->name('invite.accept');

// Accepting needs a click: the link only shows a confirmation page, this CSRF-protected POST joins the organization
Route::post('/invite/accept/{token}', [InviteAcceptController::class, 'store'])
    ->middleware('auth')
    ->name('invite.accept.store');

// Download failed CSV import rows: a signed link, for the user who ran the import only
Route::get('/contacts/import/failed-rows', DownloadFailedImportRowsController::class)
    ->middleware(['auth', 'signed'])
    ->name('contacts.import.failed-rows');

// Download an export file: a signed link, for the user who asked for the export only
Route::get('/exports/download', DownloadExportController::class)
    ->middleware(['auth', 'signed'])
    ->name('exports.download');

// Invoice PDF download (authenticated users only, tenant check in controller, 20/min and 200/h per user: SEC-57)
Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)
    ->where('invoice', '[0-9]{1,18}')
    ->middleware(['auth', 'throttle:invoice-pdf'])
    ->name('invoices.pdf');

// SB-09: the app doesn't use Filament's exporters or importers; their download routes 404 instead of failing on missing tables
Route::get('filament/exports/{export}/download', fn () => abort(404))->name('filament.exports.download');
Route::get('filament/imports/{import}/failed-rows/download', fn () => abort(404))->name('filament.imports.failed-rows.download');

// Inertia + React section (middleware scoped here to keep Filament/Livewire untouched)
Route::middleware(HandleInertiaRequests::class)
    ->prefix('app')
    ->name('app.')
    ->group(function () {
        Route::middleware(['auth', 'can:view,organization', 'tenant.context'])
            ->prefix('{organization:slug}')
            ->group(function () {
                Route::get('/tags', [TagsController::class, 'index'])->name('tags.index');
            });

        Route::get('/tags/{tag}', [TagsController::class, 'show'])
            ->where('tag', '[0-9]{1,18}')
            ->middleware('auth')
            ->name('tags.show');
    });

require __DIR__.'/settings.php';
