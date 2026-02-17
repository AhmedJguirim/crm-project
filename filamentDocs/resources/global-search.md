# Source: https://filamentphp.com/docs/4.x/resources/global-search

Resources 

# Global search 

## [#](#introduction)Introduction

Global search allows you to search across all of your resource records, from anywhere in the app.

## [#](#setting-global-search-result-titles)Setting global search result titles

To enable global search on your model, you must [set a title attribute](overview#record-titles) for your resource:

 protected static ?string $recordTitleAttribute = 'title';

This attribute is used to retrieve the search result title for that record.

NOTE

Your resource needs to have an Edit or View page to allow the global search results to link to a URL, otherwise no results will be returned for this resource.

You may customize the title further by overriding `getGlobalSearchResultTitle()` method. It may return a plain text string, or an instance of `Illuminate\Support\HtmlString` or `Illuminate\Contracts\Support\Htmlable`. This allows you to render HTML, or even Markdown, in the search result title:

 use Illuminate\Contracts\Support\Htmlable;
 
 public static function getGlobalSearchResultTitle(Model $record): string | Htmlable
 {
 return $record->name;
 }

## [#](#globally-searching-across-multiple-columns)Globally searching across multiple columns

If you would like to search across multiple columns of your resource, you may override the `getGloballySearchableAttributes()` method. “Dot notation” allows you to search inside relationships:

 public static function getGloballySearchableAttributes(): array
 {
 return ['title', 'slug', 'author.name', 'category.name'];
 }

## [#](#adding-extra-details-to-global-search-results)Adding extra details to global search results

Search results can display “details” below their title, which gives the user more information about the record. To enable this feature, you must override the `getGlobalSearchResultDetails()` method:

 public static function getGlobalSearchResultDetails(Model $record): array
 {
 return [
 'Author' => $record->author->name,
 'Category' => $record->category->name,
 ];
 }

In this example, the category and author of the record will be displayed below its title in the search result. However, the `category` and `author` relationships will be lazy-loaded, which will result in poor results performance. To [eager-load](https://laravel.com/docs/eloquent-relationships#eager-loading) these relationships, we must override the `getGlobalSearchEloquentQuery()` method:

 public static function getGlobalSearchEloquentQuery(): Builder
 {
 return parent::getGlobalSearchEloquentQuery()->with(['author', 'category']);
 }

## [#](#customizing-global-search-result-urls)Customizing global search result URLs

Global search results will link to the [Edit page](editing-records) of your resource, or the [View page](viewing-records) if the user does not have [edit permissions](editing-records#authorization). To customize this, you may override the `getGlobalSearchResultUrl()` method and return a route of your choice:

 public static function getGlobalSearchResultUrl(Model $record): string
 {
 return UserResource::getUrl('edit', ['record' => $record]);
 }

## [#](#adding-actions-to-global-search-results)Adding actions to global search results

Global search supports actions, which are buttons that render below each search result. They can open a URL or dispatch a Livewire event.

Actions can be defined as follows:

 use Filament\Actions\Action;
 
 public static function getGlobalSearchResultActions(Model $record): array
 {
 return [
 Action::make('edit')
 ->url(static::getUrl('edit', ['record' => $record])),
 ];
 }

You can learn more about how to style action buttons [here](../actions/overview).

### [#](#opening-urls-from-global-search-actions)Opening URLs from global search actions

You can open a URL, optionally in a new tab, when clicking on an action:

 use Filament\Actions\Action;
 
 Action::make('view')
 ->url(static::getUrl('view', ['record' => $record]), shouldOpenInNewTab: true)

### [#](#dispatching-livewire-events-from-global-search-actions)Dispatching Livewire events from global search actions

Sometimes you want to execute additional code when a global search result action is clicked. This can be achieved by setting a Livewire event which should be dispatched on clicking the action. You may optionally pass an array of data, which will be available as parameters in the event listener on your Livewire component:

 use Filament\Actions\Action;
 
 Action::make('quickView')
 ->dispatch('quickView', [$record->id])

## [#](#limiting-the-number-of-global-search-results)Limiting the number of global search results

By default, global search will return up to 50 results per resource. You can customize this on the resource label by overriding the `$globalSearchResultsLimit` property:

 protected static int $globalSearchResultsLimit = 20;

## [#](#moving-the-global-search-to-the-sidebar)Moving the global search to the sidebar

By default, the global search field is positioned in the topbar. If the topbar is disabled, it is added to the sidebar.

You can choose to always move it to the sidebar by passing a `position` argument to the `globalSearch()` method in the [configuration](../panel-configuration):

 use Filament\Enums\GlobalSearchPosition;
 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->globalSearch(position: GlobalSearchPosition::Sidebar);
 }

## [#](#sorting-global-search-results)Sorting global search results

By default, global search results are ordered alphabetically by resource name. You can customize this order by setting the `$globalSearchSort` property on your resource:

 protected static ?int $globalSearchSort = 3;

Now, navigation items with a lower sort value will appear before those with a higher sort value - the order is ascending.

## [#](#disabling-global-search)Disabling global search

As [explained above](#title), global search is automatically enabled once you set a title attribute for your resource. Sometimes you may want to specify the title attribute while not enabling global search.

This can be achieved by disabling global search in the [configuration](../panel-configuration):

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->globalSearch(false);
 }

## [#](#registering-global-search-key-bindings)Registering global search key bindings

The global search field can be opened using keyboard shortcuts. To configure these, pass the `globalSearchKeyBindings()` method to the [configuration](../panel-configuration):

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->globalSearchKeyBindings(['command+k', 'ctrl+k']);
 }

## [#](#configuring-the-global-search-debounce)Configuring the global search debounce

Global search has a default debounce time of 500ms, to limit the number of requests that are made while the user is typing. You can alter this by using the `globalSearchDebounce()` method in the [configuration](../panel-configuration):

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->globalSearchDebounce('750ms');
 }

## [#](#configuring-the-global-search-field-suffix)Configuring the global search field suffix

Global search field by default doesn’t include any suffix. You may customize it using the `globalSearchFieldSuffix()` method in the [configuration](../panel-configuration).

If you want to display the currently configured [global search key bindings](#registering-global-search-key-bindings) in the suffix, you can use the `globalSearchFieldKeyBindingSuffix()` method, which will display the first registered key binding as the suffix of the global search field:

 use Filament\Panel;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->globalSearchFieldKeyBindingSuffix();
 }

To customize the suffix yourself, you can pass a string or function to the `globalSearchFieldSuffix()` method. For example, to provide a custom key binding suffix for each platform manually:

 use Filament\Panel;
 use Filament\Support\Enums\Platform;
 
 public function panel(Panel $panel): Panel
 {
 return $panel
 // ...
 ->globalSearchFieldSuffix(fn (): ?string => match (Platform::detect()) {
 Platform::Windows, Platform::Linux => 'CTRL+K',
 Platform::Mac => '⌘K',
 default => null,
 });
 }

## [#](#disabling-search-term-splitting)Disabling search term splitting

By default, the global search will split the search term into individual words and search for each word separately. This allows for more flexible search queries. However, it can have a negative impact on performance when large datasets are involved. You can disable this behavior by setting the `$shouldSplitGlobalSearchTerms` property to `false` on the resource:

 protected static ?bool $shouldSplitGlobalSearchTerms = false;

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/03-resources/10-global-search.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)