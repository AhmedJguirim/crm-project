<?php

namespace App\Providers;

use App\Filament\Http\Responses\LogoutResponse;
use App\Filament\Support\SingleValueFieldState;
use App\Filament\Support\StateCasts\ScalarDateTimeStateCast;
use App\Http\Middleware\StartSession;
use App\Mail\SecuredMarkdown;
use App\Models\Organization;
use App\Policies\SegmentPolicy;
use App\Services\Segments\SegmentUsageIndex;
use App\Support\Auth\CaseInsensitiveEmailUserProvider;
use App\Support\Livewire\RefuseMistypedCallArguments;
use App\Support\Livewire\RefuseSubKeyUpdatesOfScalars;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Mail\Markdown;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider;

use function Livewire\before;
use function Livewire\on;

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

        $this->registerSessionMiddleware();
        $this->registerMailMarkdown();
        $this->registerTypeScriptTransformer();
        $this->registerFilamentStateCasts();
    }

    /**
     * The `web` group and the panel both reference the framework session middleware; the app subclass takes its place so
     * the invitation link, whose path holds the plain token, is never stored as the previous URL (BUG-62). The arguments
     * are those of the framework's own binding.
     */
    protected function registerSessionMiddleware(): void
    {
        $this->app->singleton(FrameworkStartSession::class, fn ($app): StartSession => new StartSession(
            $app->make(SessionManager::class),
            fn () => $app->make(CacheFactory::class),
        ));
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
        $this->configureAuthUserProvider();
        $this->configurePanelTimezone();
        $this->configureLivewireUpdateGuard();
        $this->configureLivewireCallGuard();
        $this->configureSingleValueFormFields();
    }

    /**
     * The `case-insensitive-email` user provider finds users by email whatever the case (BUG-65).
     */
    protected function configureAuthUserProvider(): void
    {
        Auth::provider('case-insensitive-email', fn (Application $app, array $config): CaseInsensitiveEmailUserProvider => new CaseInsensitiveEmailUserProvider($app['hash'], $config['model']));
    }

    /**
     * Refuses a Livewire update below a scalar, null or model property with a 419 before Livewire applies it (BUG-45, BUG-46).
     */
    protected function configureLivewireUpdateGuard(): void
    {
        before('update', new RefuseSubKeyUpdatesOfScalars);
    }

    /**
     * Refuses a forged Livewire call (wrong-typed or missing arguments, malformed `__dispatch`) with a 419 instead of a 500 (BUG-51, BUG-54).
     */
    protected function configureLivewireCallGuard(): void
    {
        $guard = new RefuseMistypedCallArguments;

        on('exception', [$guard, 'refuseMistypedArgument']);
        on('exception', [$guard, 'refuseMissingArgument']);
        before('call', [$guard, 'refusePaginationArguments']);
        before('call', [$guard, 'refuseMalformedDispatch']);
    }

    /**
     * A forged array in a single-value form field is refused with a validation error before the field's other rules and the save (BUG-50).
     */
    protected function configureSingleValueFormFields(): void
    {
        SingleValueFieldState::register();
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
     * Configure default behaviors for production-ready applications. In production the destructive database commands
     * and db:seed (so also migrate --seed) are refused, because the dev seed creates logins with a known password;
     * demo data goes through demo:create-account.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        SeedCommand::prohibit(app()->isProduction());

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
