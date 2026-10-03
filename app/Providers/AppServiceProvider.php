<?php

namespace App\Providers;

use App\Filament\Http\Responses\LogoutResponse;
use App\Policies\SegmentPolicy;
use App\Services\Segments\SegmentUsageIndex;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Filament\Auth\Http\Responses\Contracts\LogoutResponse::class,
            LogoutResponse::class,
        );

        $this->app->scoped(TenantContext::class);
        $this->app->scoped(SegmentUsageIndex::class);
        $this->app->scoped(SegmentPolicy::class);

        $this->registerTypeScriptTransformer();
    }

    /**
     * The transformer is a dev dependency, so only register it when installed.
     */
    protected function registerTypeScriptTransformer(): void
    {
        if (! class_exists(TypeScriptTransformerApplicationServiceProvider::class)) {
            return;
        }

        $this->app->register(TypeScriptTransformerServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
