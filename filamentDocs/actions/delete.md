# Source: https://filamentphp.com/docs/4.x/actions/delete

Actions 

# Delete action 

## [#](#introduction)Introduction

Filament includes an action that is able to delete Eloquent records. When the trigger button is clicked, a modal asks the user for confirmation. You may use it like so:

 use Filament\Actions\DeleteAction;
 
 DeleteAction::make()

Or if you want to add it as a table bulk action, so that the user can choose which rows to delete, use `Filament\Actions\DeleteBulkAction`:

 use Filament\Actions\DeleteBulkAction;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->toolbarActions([
 DeleteBulkAction::make(),
 ]);
 }

## [#](#redirecting-after-deleting)Redirecting after deleting

You may set up a custom redirect when the record is deleted using the `successRedirectUrl()` method:

 use Filament\Actions\DeleteAction;
 
 DeleteAction::make()
 ->successRedirectUrl(route('posts.list'))

As well as `$record`, the `successRedirectUrl()` function can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Action | `Filament\Actions\Action` | `$action` | The current action instance. 
Arguments | `array<string, mixed>` | `$arguments` | The array of arguments passed to the action when it was triggered. 
Data | `array<string, mixed>` | `$data` | The array of data submitted from form fields in the action's modal. It will be empty before the modal form is submitted. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current action, if one is attached. 
Mounted actions | `array<Filament\Actions\Action>` | `$mountedActions` | The array of actions that are currently mounted in the Livewire component. This is useful for accessing data from parent actions. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current action, if one is attached. 
Schema | `Filament\Schemas\Schema` | `$schema` | [Actions in schemas only] The schema object that this action belongs to. 
Schema component | `Filament\Schemas\Components\Component` | `$schemaComponent` | [Actions in schemas only] The schema component that this action belongs to. 
Schema component state | `mixed` | `$schemaComponentState` | [Actions in schemas only] The current value of the schema component. 
Schema get function | `Filament\Schemas\Components\Utilities\Get` | `$schemaGet` | [Actions in schemas only] A function for retrieving values from the schema data. Validation is not run on form fields. 
Schema operation | `string` | `$schemaOperation` | [Actions in schemas only] The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Schema set function | `Filament\Schemas\Components\Utilities\Set` | `$schemaSet` | [Actions in schemas only] A function for setting values in the schema data. 
Selected Eloquent records | `Illuminate\Support\Collection` | `$selectedRecords` | [Bulk actions only] The Eloquent records selected in the table. 
Table | `Filament\Tables\Table` | `$table` | [Actions in tables only] The table object that this action belongs to. 
 
## [#](#customizing-the-delete-notification)Customizing the delete notification

When the record is successfully deleted, a notification is dispatched to the user, which indicates the success of their action.

To customize the title of this notification, use the `successNotificationTitle()` method:

 use Filament\Actions\DeleteAction;
 
 DeleteAction::make()
 ->successNotificationTitle('User deleted')

As well as allowing a static value, the `successNotificationTitle()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Action | `Filament\Actions\Action` | `$action` | The current action instance. 
Arguments | `array<string, mixed>` | `$arguments` | The array of arguments passed to the action when it was triggered. 
Data | `array<string, mixed>` | `$data` | The array of data submitted from form fields in the action's modal. It will be empty before the modal form is submitted. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current action, if one is attached. 
Mounted actions | `array<Filament\Actions\Action>` | `$mountedActions` | The array of actions that are currently mounted in the Livewire component. This is useful for accessing data from parent actions. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current action, if one is attached. 
Schema | `Filament\Schemas\Schema` | `$schema` | [Actions in schemas only] The schema object that this action belongs to. 
Schema component | `Filament\Schemas\Components\Component` | `$schemaComponent` | [Actions in schemas only] The schema component that this action belongs to. 
Schema component state | `mixed` | `$schemaComponentState` | [Actions in schemas only] The current value of the schema component. 
Schema get function | `Filament\Schemas\Components\Utilities\Get` | `$schemaGet` | [Actions in schemas only] A function for retrieving values from the schema data. Validation is not run on form fields. 
Schema operation | `string` | `$schemaOperation` | [Actions in schemas only] The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Schema set function | `Filament\Schemas\Components\Utilities\Set` | `$schemaSet` | [Actions in schemas only] A function for setting values in the schema data. 
Selected Eloquent records | `Illuminate\Support\Collection` | `$selectedRecords` | [Bulk actions only] The Eloquent records selected in the table. 
Table | `Filament\Tables\Table` | `$table` | [Actions in tables only] The table object that this action belongs to. 
 
You may customize the entire notification using the `successNotification()` method:

 use Filament\Actions\DeleteAction;
 use Filament\Notifications\Notification;
 
 DeleteAction::make()
 ->successNotification(
 Notification::make()
 ->success()
 ->title('User deleted')
 ->body('The user has been deleted successfully.'),
 )

As well as allowing a static value, the `successNotification()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Action | `Filament\Actions\Action` | `$action` | The current action instance. 
Arguments | `array<string, mixed>` | `$arguments` | The array of arguments passed to the action when it was triggered. 
Data | `array<string, mixed>` | `$data` | The array of data submitted from form fields in the action's modal. It will be empty before the modal form is submitted. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current action, if one is attached. 
Mounted actions | `array<Filament\Actions\Action>` | `$mountedActions` | The array of actions that are currently mounted in the Livewire component. This is useful for accessing data from parent actions. 
Notification | `Filament\Notifications\Notification` | `$notification` | The default notification object, which could be a useful starting point for customization. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current action, if one is attached. 
Schema | `Filament\Schemas\Schema` | `$schema` | [Actions in schemas only] The schema object that this action belongs to. 
Schema component | `Filament\Schemas\Components\Component` | `$schemaComponent` | [Actions in schemas only] The schema component that this action belongs to. 
Schema component state | `mixed` | `$schemaComponentState` | [Actions in schemas only] The current value of the schema component. 
Schema get function | `Filament\Schemas\Components\Utilities\Get` | `$schemaGet` | [Actions in schemas only] A function for retrieving values from the schema data. Validation is not run on form fields. 
Schema operation | `string` | `$schemaOperation` | [Actions in schemas only] The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Schema set function | `Filament\Schemas\Components\Utilities\Set` | `$schemaSet` | [Actions in schemas only] A function for setting values in the schema data. 
Selected Eloquent records | `Illuminate\Support\Collection` | `$selectedRecords` | [Bulk actions only] The Eloquent records selected in the table. 
Table | `Filament\Tables\Table` | `$table` | [Actions in tables only] The table object that this action belongs to. 
 
To disable the notification altogether, use the `successNotification(null)` method:

 use Filament\Actions\DeleteAction;
 
 DeleteAction::make()
 ->successNotification(null)

## [#](#lifecycle-hooks)Lifecycle hooks

You can use the `before()` and `after()` methods to execute code before and after a record is deleted:

 use Filament\Actions\DeleteAction;
 
 DeleteAction::make()
 ->before(function () {
 // ...
 })
 ->after(function () {
 // ...
 })

These hook functions can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Action | `Filament\Actions\Action` | `$action` | The current action instance. 
Arguments | `array<string, mixed>` | `$arguments` | The array of arguments passed to the action when it was triggered. 
Data | `array<string, mixed>` | `$data` | The array of data submitted from form fields in the action's modal. It will be empty before the modal form is submitted. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current action, if one is attached. 
Mounted actions | `array<Filament\Actions\Action>` | `$mountedActions` | The array of actions that are currently mounted in the Livewire component. This is useful for accessing data from parent actions. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current action, if one is attached. 
Schema | `Filament\Schemas\Schema` | `$schema` | [Actions in schemas only] The schema object that this action belongs to. 
Schema component | `Filament\Schemas\Components\Component` | `$schemaComponent` | [Actions in schemas only] The schema component that this action belongs to. 
Schema component state | `mixed` | `$schemaComponentState` | [Actions in schemas only] The current value of the schema component. 
Schema get function | `Filament\Schemas\Components\Utilities\Get` | `$schemaGet` | [Actions in schemas only] A function for retrieving values from the schema data. Validation is not run on form fields. 
Schema operation | `string` | `$schemaOperation` | [Actions in schemas only] The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Schema set function | `Filament\Schemas\Components\Utilities\Set` | `$schemaSet` | [Actions in schemas only] A function for setting values in the schema data. 
Selected Eloquent records | `Illuminate\Support\Collection` | `$selectedRecords` | [Bulk actions only] The Eloquent records selected in the table. 
Table | `Filament\Tables\Table` | `$table` | [Actions in tables only] The table object that this action belongs to. 
 
## [#](#improving-the-performance-of-delete-bulk-actions)Improving the performance of delete bulk actions

By default, the `DeleteBulkAction` will load all Eloquent records into memory, before looping over them and deleting them one by one.

If you are deleting a large number of records, you may want to use the `chunkSelectedRecords()` method to fetch a smaller number of records at a time. This will reduce the memory usage of your application:

 use Filament\Actions\DeleteBulkAction;
 
 DeleteBulkAction::make()
 ->chunkSelectedRecords(250)

Filament loads Eloquent records into memory before deleting them for two reasons:

 * To allow individual records in the collection to be authorized with a model policy before deletion (using `authorizeIndividualRecords('delete')`, for example).
 * To ensure that model events are run when deleting records, such as the `deleting` and `deleted` events in a model observer.

If you do not require individual record policy authorization and model events, you can use the `fetchSelectedRecords(false)` method, which will not fetch the records into memory before deleting them, and instead will delete them in a single query:

 use Filament\Actions\DeleteBulkAction;
 
 DeleteBulkAction::make()
 ->fetchSelectedRecords(false)

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/actions/docs/07-delete.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)