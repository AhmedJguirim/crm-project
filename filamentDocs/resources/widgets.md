# Source: https://filamentphp.com/docs/4.x/resources/widgets

Resources 

# Using widgets on resource pages 

## [#](#introduction)Introduction

Filament allows you to display widgets inside pages, below the header and above the footer.

You can use an existing [dashboard widget](../widgets), or create one specifically for the resource.

## [#](#creating-a-resource-widget)Creating a resource widget

To get started building a resource widget:

 php artisan make:filament-widget CustomerOverview --resource=CustomerResource

This command will create two files - a widget class in the `app/Filament/Resources/Customers/Widgets` directory, and a view in the `resources/views/filament/resources/customers/widgets` directory.

You must register the new widget in your resource’s `getWidgets()` method:

 use App\Filament\Resources\Customers\Widgets\CustomerOverview;
 
 public static function getWidgets(): array
 {
 return [
 CustomerOverview::class,
 ];
 }

If you’d like to learn how to build and customize widgets, check out the [Dashboard](../widgets) documentation section.

## [#](#displaying-a-widget-on-a-resource-page)Displaying a widget on a resource page

To display a widget on a resource page, use the `getHeaderWidgets()` or `getFooterWidgets()` methods for that page:

 <?php
 
 namespace App\Filament\Resources\Customers\Pages;
 
 use App\Filament\Resources\Customers\CustomerResource;
 
 class ListCustomers extends ListRecords
 {
 public static string $resource = CustomerResource::class;
 
 protected function getHeaderWidgets(): array
 {
 return [
 CustomerResource\Widgets\CustomerOverview::class,
 ];
 }
 }

`getHeaderWidgets()` returns an array of widgets to display above the page content, whereas `getFooterWidgets()` are displayed below.

If you’d like to customize the number of grid columns used to arrange widgets, check out the [Pages documentation](../navigation/custom-pages#customizing-the-widgets-grid).

## [#](#accessing-the-current-record-in-the-widget)Accessing the current record in the widget

If you’re using a widget on an [Edit](editing-records) or [View](viewing-records) page, you may access the current record by defining a `$record` property on the widget class:

 use Illuminate\Database\Eloquent\Model;
 
 public ?Model $record = null;

## [#](#accessing-page-table-data-in-the-widget)Accessing page table data in the widget

If you’re using a widget on a [List](listing-records) page, you may access the table data by first adding the `ExposesTableToWidgets` trait to the page class:

 use Filament\Pages\Concerns\ExposesTableToWidgets;
 use Filament\Resources\Pages\ListRecords;
 
 class ListProducts extends ListRecords
 {
 use ExposesTableToWidgets;
 
 // ...
 }

Now, on the widget class, you must add the `InteractsWithPageTable` trait, and return the name of the page class from the `getTablePage()` method:

 use App\Filament\Resources\Products\Pages\ListProducts;
 use Filament\Widgets\Concerns\InteractsWithPageTable;
 use Filament\Widgets\Widget;
 
 class ProductStats extends Widget
 {
 use InteractsWithPageTable;
 
 protected function getTablePage(): string
 {
 return ListProducts::class;
 }
 
 // ...
 }

In the widget class, you can now access the Eloquent query builder instance for the table data using the `$this->getPageTableQuery()` method:

 use Filament\Widgets\StatsOverviewWidget\Stat;
 
 Stat::make('Total Products', $this->getPageTableQuery()->count()),

Alternatively, you can access a collection of the records on the current page using the `$this->getPageTableRecords()` method:

 use Filament\Widgets\StatsOverviewWidget\Stat;
 
 Stat::make('Total Products', $this->getPageTableRecords()->count()),

## [#](#accessing-the-total-table-records-count)Accessing the total table records count

If you need the total count of all records for the table query without executing an additional count query, you can use the `$tableRecordsCount` property:

 use Filament\Widgets\StatsOverviewWidget\Stat;
 
 Stat::make('Total Products', $this->tableRecordsCount),

## [#](#passing-properties-to-widgets-on-resource-pages)Passing properties to widgets on resource pages

When registering a widget on a resource page, you can use the `make()` method to pass an array of [Livewire properties](https://livewire.laravel.com/docs/properties) to it:

 protected function getHeaderWidgets(): array
 {
 return [
 CustomerResource\Widgets\CustomerOverview::make([
 'status' => 'active',
 ]),
 ];
 }

This array of properties gets mapped to [public Livewire properties](https://livewire.laravel.com/docs/properties) on the widget class:

 use Filament\Widgets\Widget;
 
 class CustomerOverview extends Widget
 {
 public string $status;
 
 // ...
 }

Now, you can access the `status` in the widget class using `$this->status`.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/03-resources/11-widgets.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)