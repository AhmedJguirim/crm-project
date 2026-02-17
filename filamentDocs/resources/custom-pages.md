# Source: https://filamentphp.com/docs/4.x/resources/custom-pages

Resources 

# Custom resource pages 

## [#](#introduction)Introduction

Filament allows you to create completely custom pages for resources. To create a new page, you can use:

 php artisan make:filament-page SortUsers --resource=UserResource --type=custom

This command will create two files - a page class in the `/Pages` directory of your resource directory, and a view in the `/pages` directory of the resource views directory.

You must register custom pages to a route in the static `getPages()` method of your resource:

 public static function getPages(): array
 {
 return [
 // ...
 'sort' => Pages\SortUsers::route('/sort'),
 ];
 }

NOTE

The order of pages registered in this method matters - any wildcard route segments that are defined before hard-coded ones will be matched by Laravel’s router first.

Any [parameters](https://laravel.com/docs/routing#route-parameters) defined in the route’s path will be available to the page class, in an identical way to [Livewire](https://livewire.laravel.com/docs/components#accessing-route-parameters).

## [#](#using-a-resource-record)Using a resource record

If you’d like to create a page that uses a record similar to the [Edit](editing-records) or [View](viewing-records) pages, you can use the `InteractsWithRecord` trait:

 use Filament\Resources\Pages\Page;
 use Filament\Resources\Pages\Concerns\InteractsWithRecord;
 
 class ManageUser extends Page
 {
 use InteractsWithRecord;
 
 public function mount(int | string $record): void
 {
 $this->record = $this->resolveRecord($record);
 }
 
 // ...
 }

The `mount()` method should resolve the record from the URL and store it in `$this->record`. You can access the record at any time using `$this->getRecord()` in the class or view.

To add the record to the route as a parameter, you must define `{record}` in `getPages()`:

 public static function getPages(): array
 {
 return [
 // ...
 'manage' => Pages\ManageUser::route('/{record}/manage'),
 ];
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/03-resources/12-custom-pages.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)