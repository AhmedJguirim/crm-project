# Source: https://filamentphp.com/docs/4.x/resources/deleting-records

Resources 

# Deleting records 

## [#](#handling-soft-deletes)Handling soft-deletes

## [#](#creating-a-resource-with-soft-delete)Creating a resource with soft-delete

By default, you will not be able to interact with deleted records in the app. If you’d like to add functionality to restore, force-delete and filter trashed records in your resource, use the `--soft-deletes` flag when generating the resource:

 php artisan make:filament-resource Customer --soft-deletes

## [#](#adding-soft-deletes-to-an-existing-resource)Adding soft-deletes to an existing resource

Alternatively, you may add soft-deleting functionality to an existing resource.

Firstly, you must update the resource:

 use Filament\Actions\BulkActionGroup;
 use Filament\Actions\DeleteAction;
 use Filament\Actions\DeleteBulkAction;
 use Filament\Actions\ForceDeleteAction;
 use Filament\Actions\ForceDeleteBulkAction;
 use Filament\Actions\RestoreAction;
 use Filament\Actions\RestoreBulkAction;
 use Filament\Tables\Filters\TrashedFilter;
 use Filament\Tables\Table;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\SoftDeletingScope;
 
 public static function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->filters([
 TrashedFilter::make(),
 // ...
 ])
 ->recordActions([
 // You may add these actions to your table if you're using a simple
 // resource, or you just want to be able to delete records without
 // leaving the table.
 DeleteAction::make(),
 ForceDeleteAction::make(),
 RestoreAction::make(),
 // ...
 ])
 ->toolbarActions([
 BulkActionGroup::make([
 DeleteBulkAction::make(),
 ForceDeleteBulkAction::make(),
 RestoreBulkAction::make(),
 // ...
 ]),
 ]);
 }
 
 public static function getRecordRouteBindingEloquentQuery(): Builder
 {
 return parent::getRecordRouteBindingEloquentQuery()
 ->withoutGlobalScopes([
 SoftDeletingScope::class,
 ]);
 }

Now, update the Edit page class if you have one:

 use Filament\Actions;
 
 protected function getHeaderActions(): array
 {
 return [
 Actions\DeleteAction::make(),
 Actions\ForceDeleteAction::make(),
 Actions\RestoreAction::make(),
 // ...
 ];
 }

## [#](#deleting-records-on-the-list-page)Deleting records on the List page

By default, you can bulk-delete records in your table. You may also wish to delete single records, using a `DeleteAction`:

 use Filament\Actions\DeleteAction;
 use Filament\Tables\Table;
 
 public static function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->recordActions([
 // ...
 DeleteAction::make(),
 ]);
 }

## [#](#authorization)Authorization

For authorization, Filament will observe any [model policies](https://laravel.com/docs/authorization#creating-policies) that are registered in your app.

Users may delete records if the `delete()` method of the model policy returns `true`.

They also have the ability to bulk-delete records if the `deleteAny()` method of the policy returns `true`. Filament uses the `deleteAny()` method because iterating through multiple records and checking the `delete()` policy is not very performant.

You can use the `authorizeIndividualRecords()` method on the `BulkDeleteAction` to check the `delete()` policy for each record individually.

### [#](#authorizing-soft-deletes)Authorizing soft-deletes

The `forceDelete()` policy method is used to prevent a single soft-deleted record from being force-deleted. `forceDeleteAny()` is used to prevent records from being bulk force-deleted. Filament uses the `forceDeleteAny()` method because iterating through multiple records and checking the `forceDelete()` policy is not very performant.

The `restore()` policy method is used to prevent a single soft-deleted record from being restored. `restoreAny()` is used to prevent records from being bulk restored. Filament uses the `restoreAny()` method because iterating through multiple records and checking the `restore()` policy is not very performant.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/03-resources/06-deleting-records.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)