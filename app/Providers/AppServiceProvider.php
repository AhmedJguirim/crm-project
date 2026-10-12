<?php

namespace App\Providers;

use App\Filament\Http\Responses\LogoutResponse;
use App\Models\Organization;
use App\Policies\SegmentPolicy;
use App\Services\Segments\SegmentUsageIndex;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
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
        $this->configurePanelTimezone();
    }

    /**
     * Dates and times are picked and shown in the timezone of the current organization. The closure is evaluated on every
     * read, so a tenant switch never keeps the previous timezone; without a tenant the app timezone (UTC) applies.
     */
    protected function configurePanelTimezone(): void
    {
        FilamentTimezone::set(fn (): ?string => ($tenant = Filament::getTenant()) instanceof Organization
            ? Organization::validTimezoneOrUtc($tenant->timezone)
            : null);
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
