<?php

namespace App\Providers;

use App\Filament\Http\Responses\LogoutResponse;
use App\Filament\Support\StateCasts\ScalarDateTimeStateCast;
use App\Mail\SecuredMarkdown;
use App\Models\Organization;
use App\Policies\SegmentPolicy;
use App\Services\Segments\SegmentUsageIndex;
use App\Support\Livewire\RefuseSubKeyUpdatesOfScalars;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;

use function Livewire\before;

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

        $this->registerMailMarkdown();
        $this->registerTypeScriptTransformer();
        $this->registerFilamentStateCasts();
    }

    /**
     * User text (organization, user and task names) is interpolated into Markdown mails; the secured renderer keeps it
     * from turning into links or images. It keeps the theme, paths and extensions configured for the framework renderer.
     */
    protected function registerMailMarkdown(): void
    {
        $this->app->extend(Markdown::class, fn (Markdown $markdown, $app): Markdown => new SecuredMarkdown($app->make('view'), [
            'theme' => $app->make('config')->get('mail.markdown.theme', 'default'),
            'paths' => $app->make('config')->get('mail.markdown.paths', []),
            'extensions' => $app->make('config')->get('mail.markdown.extensions', []),
        ]));
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
     * Every date picker reads its state through the forged-state-safe cast (BUG-43).
     */
    protected function registerFilamentStateCasts(): void
    {
        $this->app->bind(DateTimeStateCast::class, ScalarDateTimeStateCast::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePanelTimezone();
        $this->configureLivewireUpdateGuard();
    }

    /**
     * Refuses a Livewire update below a scalar, null or model property with a 419 before Livewire applies it (BUG-45, BUG-46).
     */
    protected function configureLivewireUpdateGuard(): void
    {
        before('update', new RefuseSubKeyUpdatesOfScalars);
    }

    /**
     * Dates and times are picked and shown in the timezone of the current organization. The closure is evaluated on every
     * read, so a tenant switch never keeps the previous timezone; without a tenant the app timezone (UTC) applies.
     */
    protected function configurePanelTimezone(): void
    {
        FilamentTimezone::set(fn (): ?string => ($tenant = CurrentOrganization::find()) !== null
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
