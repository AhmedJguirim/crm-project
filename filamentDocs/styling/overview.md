# Source: https://filamentphp.com/docs/4.x/styling/overview

Customizing styling 

# Overview 

## [#](#changing-the-colors)Changing the colors

In the [configuration](../panel-configuration), you can easily change the colors that are used. Filament ships with 6 predefined colors that are used everywhere within the framework. They are customizable as follows:

 use Filament\Panel;
 use Filament\Support\Colors\Color;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->colors([
 'danger' => Color::Rose,
 'gray' => Color::Gray,
 'info' => Color::Blue,
 'primary' => Color::Indigo,
 'success' => Color::Emerald,
 'warning' => Color::Orange,
 ]);
 }

The `Filament\Support\Colors\Color` class contains color options for all [Tailwind CSS color palettes](https://tailwindcss.com/docs/customizing-colors).

You can also pass in a function to `register()` which will only get called when the app is getting rendered. This is useful if you are calling `register()` from a service provider, and want to access objects like the currently authenticated user, which are initialized later in middleware.

Alternatively, you may pass your own palette in as an array of OKLCH colors:

 $panel
 ->colors([
 'primary' => [
 50 => 'oklch(0.969 0.015 12.422)',
 100 => 'oklch(0.941 0.03 12.58)',
 200 => 'oklch(0.892 0.058 10.001)',
 300 => 'oklch(0.81 0.117 11.638)',
 400 => 'oklch(0.712 0.194 13.428)',
 500 => 'oklch(0.645 0.246 16.439)',
 600 => 'oklch(0.586 0.253 17.585)',
 700 => 'oklch(0.514 0.222 16.935)',
 800 => 'oklch(0.455 0.188 13.697)',
 900 => 'oklch(0.41 0.159 10.272)',
 950 => 'oklch(0.271 0.105 12.094)',
 ],
 ])

### [#](#generating-a-color-palette)Generating a color palette

If you want us to attempt to generate a palette for you based on a singular hex or RGB value, you can pass that in:

 $panel
 ->colors([
 'primary' => '#6366f1',
 ])
 
 $panel
 ->colors([
 'primary' => 'rgb(99, 102, 241)',
 ])

## [#](#changing-the-font)Changing the font

By default, we use the [Inter](https://fonts.google.com/specimen/Inter) font. You can change this using the `font()` method in the [configuration](../panel-configuration) file:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->font('Poppins');
 }

All [Google Fonts](https://fonts.google.com) are available to use.

### [#](#changing-the-font-provider)Changing the font provider

[Bunny Fonts CDN](https://fonts.bunny.net) is used to serve the fonts. It is GDPR-compliant. If you’d like to use [Google Fonts CDN](https://fonts.google.com) instead, you can do so using the `provider` argument of the `font()` method:

 use Filament\FontProviders\GoogleFontProvider;
 
 $panel->font('Inter', provider: GoogleFontProvider::class)

Or if you’d like to serve the fonts from a local stylesheet, you can use the `LocalFontProvider`:

 use Filament\FontProviders\LocalFontProvider;
 
 $panel->font(
 'Inter',
 url: asset('css/fonts.css'),
 provider: LocalFontProvider::class,
 )

## [#](#creating-a-custom-theme)Creating a custom theme

Filament allows you to change the CSS used to render the UI by compiling a custom stylesheet to replace the default one. This custom stylesheet is called a “theme”. Themes use [Tailwind CSS](https://tailwindcss.com).

To create a custom theme for a panel, you can use the `php artisan make:filament-theme` command:

 php artisan make:filament-theme

If you have multiple panels, you can specify the panel you want to create a theme for:

 php artisan make:filament-theme admin

By default, this command will use NPM to install dependencies. If you want to use a different package manager, you can use the `--pm` option:

 php artisan make:filament-theme --pm=bun

This command will:

 1. Install the required Tailwind CSS dependencies
 2. Generate a CSS file in `resources/css/filament/{panel}/theme.css`
 3. Attempt to automatically add the theme to your `vite.config.js` input array
 4. Attempt to automatically register `->viteTheme()` in your panel provider
 5. Offer to compile the theme with Vite

If the command cannot automatically configure your files (due to non-standard formatting), it will display manual instructions instead. In that case, follow these steps:

### [#](#manual-configuration)Manual configuration

Add the theme’s CSS file to the Laravel plugin’s `input` array in `vite.config.js`:

 input: [
 // ...
 'resources/css/filament/admin/theme.css',
 ]

Register the Vite-compiled theme CSS file in the panel’s provider:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->viteTheme('resources/css/filament/admin/theme.css');
 }

Then compile the theme with Vite:

 npm run build

NOTE

Check the command output for the exact file path (e.g., `admin/theme.css`), as it may vary depending on your panel’s ID.

You can now customize the theme by editing the CSS file in `resources/css/filament`.

## [#](#using-tailwind-css-classes-in-your-blade-views-or-php-files)Using Tailwind CSS classes in your Blade views or PHP files

NOTE

**A custom theme is required to use Tailwind CSS classes in your own code.** Filament’s default compiled stylesheet does not include arbitrary Tailwind classes - it only contains the styles needed for Filament’s own UI components.

If you want to use Tailwind CSS utility classes (like `text-primary-600`, `bg-gray-100`, `p-4`, etc.) in your own Blade views, Livewire components, or PHP files, **you must create a custom theme first**.

Without a custom theme, any Tailwind classes you add to your code will simply not work - the styles won’t be applied because they’re not included in the compiled CSS.

### [#](#setting-up-tailwind-css-for-your-project)Setting up Tailwind CSS for your project

To use Tailwind CSS classes in your project, you need to set up a [custom theme](#creating-a-custom-theme). Run the following command:

 php artisan make:filament-theme

In the generated `theme.css` file, you will find `@source` directives that tell Tailwind CSS where to scan for classes:

 @source '../../../../app/Filament';
 @source '../../../../resources/views/filament';

**Add your own directories** where you use Tailwind classes. For example:

 @source '../../../../app/Filament';
 @source '../../../../resources/views/filament';
 @source '../../../../resources/views/components';
 @source '../../../../resources/views/livewire';
 @source '../../../../app/Livewire';

After adding your directories, rebuild your theme:

 npm run build

You can [learn more about the `@source` directive](https://tailwindcss.com/docs/detecting-classes-in-source-files#explicitly-registering-sources) in the Tailwind CSS documentation.

## [#](#disabling-dark-mode)Disabling dark mode

To disable dark mode switching, you can use the [configuration](../panel-configuration) file:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->darkMode(false);
 }

## [#](#changing-the-default-theme-mode)Changing the default theme mode

By default, Filament uses the user’s system theme as the default mode. For example, if the user’s computer is in dark mode, Filament will use dark mode by default. The system mode in Filament is reactive if the user changes their computer’s mode. If you want to change the default mode to force light or dark mode, you can use the `defaultThemeMode()` method, passing `ThemeMode::Light` or `ThemeMode::Dark`:

 use Filament\Enums\ThemeMode;
 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->defaultThemeMode(ThemeMode::Light);
 }

## [#](#adding-a-logo)Adding a logo

By default, Filament uses your app’s name to render a simple text-based logo. However, you can easily customize this.

If you want to simply change the text that is used in the logo, you can use the `brandName()` method:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->brandName('Filament Demo');
 }

To render an image instead, you can pass a URL to the `brandLogo()` method:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->brandLogo(asset('images/logo.svg'));
 }

Alternatively, you may directly pass HTML to the `brandLogo()` method to render an inline SVG element for example:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->brandLogo(fn () => view('filament.admin.logo'));
 }

 <svg
 viewBox="0 0 128 26"
 xmlns="http://www.w3.org/2000/svg"
 class="h-full fill-gray-500 dark:fill-gray-400"
 >
 <!-- ... -->
 </svg>

If you need a different logo to be used when the application is in dark mode, you can pass it to `darkModeBrandLogo()` in the same way.

The logo height defaults to a sensible value, but it’s impossible to account for all possible aspect ratios. Therefore, you may customize the height of the rendered logo using the `brandLogoHeight()` method:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->brandLogo(fn () => view('filament.admin.logo'))
 ->brandLogoHeight('2rem');
 }

## [#](#adding-a-favicon)Adding a favicon

To add a favicon, you can use the [configuration](../panel-configuration) file, passing the public URL of the favicon:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->favicon(asset('images/favicon.png'));
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/08-styling/01-overview.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)