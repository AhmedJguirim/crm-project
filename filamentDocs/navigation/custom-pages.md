# Source: https://filamentphp.com/docs/4.x/navigation/custom-pages

Navigation 

# Custom pages 

## [#](#introduction)Introduction

Filament allows you to create completely custom pages for the app.

## [#](#creating-a-page)Creating a page

To create a new page, you can use:

 php artisan make:filament-page Settings

This command will create two files - a page class in the `/Pages` directory of the Filament directory, and a view in the `/pages` directory of the Filament views directory.

Page classes are all full-page [Livewire](https://livewire.laravel.com) components with a few extra utilities you can use with the panel.

## [#](#authorization)Authorization

You can prevent pages from appearing in the menu by overriding the `canAccess()` method in your Page class. This is useful if you want to control which users can see the page in the navigation, and also which users can visit the page directly:

 public static function canAccess(): bool
 {
 return auth()->user()->canManageSettings();
 }

## [#](#adding-actions-to-pages)Adding actions to pages

Actions are buttons that can perform tasks on the page, or visit a URL. You can read more about their capabilities [here](../actions).

Since all pages are Livewire components, you can [add actions](../components/action#adding-the-action) anywhere. Pages already have the `InteractsWithActions` trait, `HasActions` interface, and `<x-filament-actions::modals />` Blade component all set up for you.

### [#](#header-actions)Header actions

You can also easily add actions to the header of any page, including [resource pages](../resources/overview). You don’t need to worry about adding anything to the Blade template, we handle that for you. Just return your actions from the `getHeaderActions()` method of the page class:

 use Filament\Actions\Action;
 
 protected function getHeaderActions(): array
 {
 return [
 Action::make('edit')
 ->url(route('posts.edit', ['post' => $this->post])),
 Action::make('delete')
 ->requiresConfirmation()
 ->action(fn () => $this->post->delete()),
 ];
 }

#### [#](#aligning-header-actions)Aligning header actions

By default, header actions are aligned to the left on mobile. To change the alignment of the header actions on mobile, set `$headerActionsAlignment`:

 use Filament\Support\Enums\Alignment;
 
 protected ?Alignment $headerActionsAlignment = Alignment::End;

### [#](#opening-an-action-modal-when-a-page-loads)Opening an action modal when a page loads

You can also open an action when a page loads by setting the `$defaultAction` property to the name of the action you want to open:

 use Filament\Actions\Action;
 
 public $defaultAction = 'onboarding';
 
 public function onboardingAction(): Action
 {
 return Action::make('onboarding')
 ->modalHeading('Welcome')
 ->visible(fn (): bool => ! auth()->user()->isOnBoarded());
 }

You can also pass an array of arguments to the default action using the `$defaultActionArguments` property:

 public $defaultActionArguments = ['step' => 2];

Alternatively, you can open an action modal when a page loads by specifying the `action` as a query string parameter to the page:

 /admin/products/edit/932510?action=onboarding

### [#](#refreshing-form-data)Refreshing form data

If you’re using actions on an [Edit](../resources/editing-records) or [View](../resources/viewing-records) resource page, you can refresh data within the main form using the `refreshFormData()` method:

 use App\Models\Post;
 use Filament\Actions\Action;
 
 Action::make('approve')
 ->action(function (Post $record) {
 $record->approve();
 
 $this->refreshFormData([
 'status',
 ]);
 })

This method accepts an array of model attributes that you wish to refresh in the form.

## [#](#adding-widgets-to-pages)Adding widgets to pages

Filament allows you to display [widgets](../widgets) inside pages, below the header and above the footer.

To add a widget to a page, use the `getHeaderWidgets()` or `getFooterWidgets()` methods:

 use App\Filament\Widgets\StatsOverviewWidget;
 
 protected function getHeaderWidgets(): array
 {
 return [
 StatsOverviewWidget::class
 ];
 }

`getHeaderWidgets()` returns an array of widgets to display above the page content, whereas `getFooterWidgets()` are displayed below.

If you’d like to learn how to build and customize widgets, check out the [Widgets](../widgets) documentation section.

### [#](#customizing-the-widgets-grid)Customizing the widgets’ grid

You may change how many grid columns are used to display widgets.

You may override the `getHeaderWidgetsColumns()` or `getFooterWidgetsColumns()` methods to return a number of grid columns to use:

 public function getHeaderWidgetsColumns(): int | array
 {
 return 3;
 }

#### [#](#responsive-widgets-grid)Responsive widgets grid

You may wish to change the number of widget grid columns based on the responsive [breakpoint](https://tailwindcss.com/docs/responsive-design#overview) of the browser. You can do this using an array that contains the number of columns that should be used at each breakpoint:

 public function getHeaderWidgetsColumns(): int | array
 {
 return [
 'md' => 4,
 'xl' => 5,
 ];
 }

This pairs well with [responsive widget widths](../widgets#responsive-widget-widths).

#### [#](#passing-data-to-widgets-from-the-page)Passing data to widgets from the page

You may pass data to widgets from the page using the `getWidgetData()` method:

 public function getWidgetData(): array
 {
 return [
 'stats' => [
 'total' => 100,
 ],
 ];
 }

Now, you can define a corresponding public `$stats` array property on the widget class, which will be automatically filled:

 public $stats = [];

### [#](#passing-properties-to-widgets-on-pages)Passing properties to widgets on pages

When registering a widget on a page, you can use the `make()` method to pass an array of [Livewire properties](https://livewire.laravel.com/docs/properties) to it:

 use App\Filament\Widgets\StatsOverviewWidget;
 
 protected function getHeaderWidgets(): array
 {
 return [
 StatsOverviewWidget::make([
 'status' => 'active',
 ]),
 ];
 }

This array of properties gets mapped to [public Livewire properties](https://livewire.laravel.com/docs/properties) on the widget class:

 use Filament\Widgets\Widget;
 
 class StatsOverviewWidget extends Widget
 {
 public string $status;
 
 // ...
 }

Now, you can access the `status` in the widget class using `$this->status`.

## [#](#customizing-the-page-title)Customizing the page title

By default, Filament will automatically generate a title for your page based on its name. You may override this by defining a `$title` property on your page class:

 protected static ?string $title = 'Custom Page Title';

Alternatively, you may return a string from the `getTitle()` method:

 use Illuminate\Contracts\Support\Htmlable;
 
 public function getTitle(): string | Htmlable
 {
 return __('Custom Page Title');
 }

## [#](#customizing-the-page-navigation-label)Customizing the page navigation label

By default, Filament will use the page’s [title](#customizing-the-page-title) as its [navigation](overview) item label. You may override this by defining a `$navigationLabel` property on your page class:

 protected static ?string $navigationLabel = 'Custom Navigation Label';

Alternatively, you may return a string from the `getNavigationLabel()` method:

 public static function getNavigationLabel(): string
 {
 return __('Custom Navigation Label');
 }

## [#](#customizing-the-page-url)Customizing the page URL

By default, Filament will automatically generate a URL (slug) for your page based on its name. You may override this by defining a `$slug` property on your page class:

 protected static ?string $slug = 'custom-url-slug';

## [#](#customizing-the-page-heading)Customizing the page heading

By default, Filament will use the page’s [title](#customizing-the-page-title) as its heading. You may override this by defining a `$heading` property on your page class:

 protected ?string $heading = 'Custom Page Heading';

Alternatively, you may return a string from the `getHeading()` method:

 public function getHeading(): string
 {
 return __('Custom Page Heading');
 }

### [#](#adding-a-page-subheading)Adding a page subheading

You may also add a subheading to your page by defining a `$subheading` property on your page class:

 protected ?string $subheading = 'Custom Page Subheading';

Alternatively, you may return a string from the `getSubheading()` method:

 public function getSubheading(): ?string
 {
 return __('Custom Page Subheading');
 }

## [#](#replacing-the-page-header-with-a-custom-view)Replacing the page header with a custom view

You may replace the default [heading](#customizing-the-page-heading), [subheading](#adding-a-page-subheading) and [actions](#header-actions) with a custom header view for any page. You may return it from the `getHeader()` method:

 use Illuminate\Contracts\View\View;
 
 public function getHeader(): ?View
 {
 return view('filament.settings.custom-header');
 }

This example assumes you have a Blade view at `resources/views/filament/settings/custom-header.blade.php`.

## [#](#rendering-a-custom-view-in-the-footer-of-the-page)Rendering a custom view in the footer of the page

You may also add a footer to any page, below its content. You may return it from the `getFooter()` method:

 use Illuminate\Contracts\View\View;
 
 public function getFooter(): ?View
 {
 return view('filament.settings.custom-footer');
 }

This example assumes you have a Blade view at `resources/views/filament/settings/custom-footer.blade.php`.

## [#](#customizing-the-maximum-content-width)Customizing the maximum content width

By default, Filament will restrict the width of the content on the page, so it doesn’t become too wide on large screens. To change this, you may override the `getMaxContentWidth()` method. Options correspond to [Tailwind’s max-width scale](https://tailwindcss.com/docs/max-width). The options are `ExtraSmall`, `Small`, `Medium`, `Large`, `ExtraLarge`, `TwoExtraLarge`, `ThreeExtraLarge`, `FourExtraLarge`, `FiveExtraLarge`, `SixExtraLarge`, `SevenExtraLarge`, `Full`, `MinContent`, `MaxContent`, `FitContent`, `Prose`, `ScreenSmall`, `ScreenMedium`, `ScreenLarge`, `ScreenExtraLarge` and `ScreenTwoExtraLarge`. The default is `SevenExtraLarge`:

 use Filament\Support\Enums\Width;
 
 public function getMaxContentWidth(): Width
 {
 return Width::Full;
 }

## [#](#generating-urls-to-pages)Generating URLs to pages

Filament provides `getUrl()` static method on page classes to generate URLs to them. Traditionally, you would need to construct the URL by hand or by using Laravel’s `route()` helper, but these methods depend on knowledge of the page’s slug or route naming conventions.

The `getUrl()` method, without any arguments, will generate a URL:

 use App\Filament\Pages\Settings;
 
 Settings::getUrl(); // /admin/settings

If your page uses URL / query parameters, you should use the argument:

 use App\Filament\Pages\Settings;
 
 Settings::getUrl(['section' => 'notifications']); // /admin/settings?section=notifications

### [#](#generating-urls-to-pages-in-other-panels)Generating URLs to pages in other panels

If you have multiple panels in your app, `getUrl()` will generate a URL within the current panel. You can also indicate which panel the page is associated with, by passing the panel ID to the `panel` argument:

 use App\Filament\Pages\Settings;
 
 Settings::getUrl(panel: 'marketing');

## [#](#adding-sub-navigation-between-pages)Adding sub-navigation between pages

You may want to add a common sub-navigation to multiple pages, to allow users to quickly navigate between them. You can do this by defining a [cluster](clusters). Clusters can also contain [resources](../resources/overview), and you can switch between multiple pages or resources within a cluster.

## [#](#setting-the-sub-navigation-position)Setting the sub-navigation position

The sub-navigation is rendered at the start of the page by default. You may change the position for a page by setting the `$subNavigationPosition` property on the page. The value may be `SubNavigationPosition::Start`, `SubNavigationPosition::End`, or `SubNavigationPosition::Top` to render the sub-navigation as tabs:

 use Filament\Pages\Enums\SubNavigationPosition;
 
 protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;

## [#](#adding-extra-attributes-to-the-body-tag-of-a-page)Adding extra attributes to the body tag of a page

You may wish to add extra attributes to the `<body>` tag of a page. To do this, you can set an array of attributes in `$extraBodyAttributes`:

 protected array $extraBodyAttributes = [];

Or, you can return an array of attributes and their values from the `getExtraBodyAttributes()` method:

 public function getExtraBodyAttributes(): array
 {
 return [
 'class' => 'settings-page',
 ];
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/06-navigation/02-custom-pages.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)