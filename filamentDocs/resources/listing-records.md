# Source: https://filamentphp.com/docs/4.x/resources/listing-records

Resources 

# Listing records 

## [#](#using-tabs-to-filter-the-records)Using tabs to filter the records

You can add tabs above the table, which can be used to filter the records based on some predefined conditions. Each tab can scope the Eloquent query of the table in a different way. To register tabs, add a `getTabs()` method to the List page class, and return an array of `Tab` objects:

 use Filament\Schemas\Components\Tabs\Tab;
 use Illuminate\Database\Eloquent\Builder;
 
 public function getTabs(): array
 {
 return [
 'all' => Tab::make(),
 'active' => Tab::make()
 ->modifyQueryUsing(fn (Builder $query) => $query->where('active', true)),
 'inactive' => Tab::make()
 ->modifyQueryUsing(fn (Builder $query) => $query->where('active', false)),
 ];
 }

### [#](#customizing-the-filter-tab-labels)Customizing the filter tab labels

The keys of the array will be used as identifiers for the tabs, so they can be persisted in the URL’s query string. The label of each tab is also generated from the key, but you can override that by passing a label into the `make()` method of the tab:

 use Filament\Schemas\Components\Tabs\Tab;
 use Illuminate\Database\Eloquent\Builder;
 
 public function getTabs(): array
 {
 return [
 'all' => Tab::make('All customers'),
 'active' => Tab::make('Active customers')
 ->modifyQueryUsing(fn (Builder $query) => $query->where('active', true)),
 'inactive' => Tab::make('Inactive customers')
 ->modifyQueryUsing(fn (Builder $query) => $query->where('active', false)),
 ];
 }

### [#](#adding-icons-to-filter-tabs)Adding icons to filter tabs

You can add icons to the tabs by passing an [icon](../styling/icons) into the `icon()` method of the tab:

 use Filament\Schemas\Components\Tabs\Tab;
 
 Tab::make()
 ->icon('heroicon-m-user-group')

You can also change the icon’s position to be after the label instead of before it, using the `iconPosition()` method:

 use Filament\Support\Enums\IconPosition;
 
 Tab::make()
 ->icon('heroicon-m-user-group')
 ->iconPosition(IconPosition::After)

### [#](#adding-badges-to-filter-tabs)Adding badges to filter tabs

You can add badges to the tabs by passing a string into the `badge()` method of the tab:

 use Filament\Schemas\Components\Tabs\Tab;
 
 Tab::make()
 ->badge(Customer::query()->where('active', true)->count())

#### [#](#changing-the-color-of-filter-tab-badges)Changing the color of filter tab badges

The color of a badge may be changed using the `badgeColor()` method:

 use Filament\Schemas\Components\Tabs\Tab;
 
 Tab::make()
 ->badge(Customer::query()->where('active', true)->count())
 ->badgeColor('success')

### [#](#adding-extra-attributes-to-filter-tabs)Adding extra attributes to filter tabs

You may also pass extra HTML attributes to filter tabs using `extraAttributes()`:

 use Filament\Schemas\Components\Tabs\Tab;
 
 Tab::make()
 ->extraAttributes(['data-cy' => 'statement-confirmed-tab'])

### [#](#customizing-the-default-tab)Customizing the default tab

To customize the default tab that is selected when the page is loaded, you can return the array key of the tab from the `getDefaultActiveTab()` method:

 use Filament\Schemas\Components\Tabs\Tab;
 
 public function getTabs(): array
 {
 return [
 'all' => Tab::make(),
 'active' => Tab::make(),
 'inactive' => Tab::make(),
 ];
 }
 
 public function getDefaultActiveTab(): string | int | null
 {
 return 'active';
 }

## [#](#authorization)Authorization

For authorization, Filament will observe any [model policies](https://laravel.com/docs/authorization#creating-policies) that are registered in your app.

Users may access the List page if the `viewAny()` method of the model policy returns `true`.

The `reorder()` method is used to control [reordering a record](#reordering-records).

## [#](#customizing-the-table-eloquent-query)Customizing the table Eloquent query

Although you can [customize the Eloquent query for the entire resource](overview#customizing-the-resource-eloquent-query), you may also make specific modifications for the List page table. To do this, use the `modifyQueryUsing()` method in the `table()` method of the resource:

 use Filament\Tables\Table;
 use Illuminate\Database\Eloquent\Builder;
 
 public static function table(Table $table): Table
 {
 return $table
 ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes());
 }

## [#](#custom-page-content)Custom page content

Each page in Filament has its own [schema](../schemas), which defines the overall structure and content. You can override the schema for the page by defining a `content()` method on it. The `content()` method for the List page contains the following components by default:

 use Filament\Schemas\Components\EmbeddedTable;
 use Filament\Schemas\Components\RenderHook;
 use Filament\Schemas\Schema;
 
 public function content(Schema $schema): Schema
 {
 return $schema
 ->components([
 $this->getTabsContentComponent(), // This method returns a component to display the tabs above a table
 RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
 EmbeddedTable::make(), // This is the component that renders the table that is defined in this resource
 RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
 ]);
 }

Inside the `components()` array, you can insert any [schema component](../schemas). You can reorder the components by changing the order of the array or remove any of the components that are not needed.

### [#](#using-a-custom-blade-view)Using a custom Blade view

For further customization opportunities, you can override the static `$view` property on the page class to a custom view in your app:

 protected string $view = 'filament.resources.users.pages.list-users';

This assumes that you have created a view at `resources/views/filament/resources/users/pages/list-users.blade.php`:

 <x-filament-panels::page>
 {{ $this->content }} {{-- This will render the content of the page defined in the `content()` method, which can be removed if you want to start from scratch --}}
 </x-filament-panels::page>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/03-resources/02-listing-records.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)