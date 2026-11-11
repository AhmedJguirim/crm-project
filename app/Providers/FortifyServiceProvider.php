<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Responses\PasswordResetLinkSentResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkSentResponse::class);
        $this->app->bind(NewPasswordController::class, ResetPasswordController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting: `password-reset` limits the POSTs of the forgot-password and reset-password
     * routes per IP (5 a minute, 20 an hour) and lets every other Fortify route through. The sign-in (and its
     * second factor) is the panel login, which has its own limits.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('password-reset', function (Request $request) {
            if (! $request->isMethod('POST') || ! in_array($request->route()?->getName(), ['password.email', 'password.update'], true)) {
                return Limit::none();
            }

            return [
                Limit::perMinute(5)->by('minute|'.$request->ip()),
                Limit::perHour(20)->by('hour|'.$request->ip()),
            ];
        });
    }
}
