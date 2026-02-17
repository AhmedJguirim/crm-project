# Source: https://filamentphp.com/docs/4.x/actions/edit

Actions 

# Edit action 

## [#](#introduction)Introduction

Filament includes an action that is able to edit Eloquent records. When the trigger button is clicked, a modal will open with a form inside. The user fills the form, and that data is validated and saved into the database. You may use it like so:

 use Filament\Actions\EditAction;
 use Filament\Forms\Components\TextInput;
 
 EditAction::make()
 ->schema([
 TextInput::make('title')
 ->required()
 ->maxLength(255),
 // ...
 ])

## [#](#customizing-data-before-filling-the-form)Customizing data before filling the form

You may wish to modify the data from a record before it is filled into the form. To do this, you may use the `mutateRecordDataUsing()` method to modify the `$data` array, and return the modified version before it is filled into the form:

 use Filament\Actions\EditAction;
 
 EditAction::make()
 ->mutateRecordDataUsing(function (array $data): array {
 $data['user_id'] = auth()->id();
 
 return $data;
 })

As well as `$data`, the `mutateRecordDataUsing()` function can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
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
 
## [#](#customizing-data-before-saving)Customizing data before saving

Sometimes, you may wish to modify form data before it is finally saved to the database. To do this, you may use the `mutateDataUsing()` method, which has access to the `$data` as an array, and returns the modified version:

 use Filament\Actions\EditAction;
 
 EditAction::make()
 ->mutateDataUsing(function (array $data): array {
 $data['last_edited_by_id'] = auth()->id();
 
 return $data;
 })

As well as `$data`, the `mutateDataUsing()` function can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
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
 
## [#](#customizing-the-saving-process)Customizing the saving process

You can tweak how the record is updated with the `using()` method:

 use Filament\Actions\EditAction;
 use Illuminate\Database\Eloquent\Model;
 
 EditAction::make()
 ->using(function (Model $record, array $data): Model {
 $record->update($data);
 
 return $record;
 })

As well as `$record` and `$data`, the `using()` function can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/actions/overview#action-utility-injection) Utility | Type | Parameter | Description 
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
 
## [#](#redirecting-after-saving)Redirecting after saving

You may set up a custom redirect when the form is submitted using the `successRedirectUrl()` method:

 use Filament\Actions\EditAction;
 
 EditAction::make()
 ->successRedirectUrl(route('posts.list'))

If you want to redirect using the created record, use the `$record` parameter:

 use Filament\Actions\EditAction;
 use Illuminate\Database\Eloquent\Model;
 
 EditAction::make()
 ->successRedirectUrl(fn (Model $record): string => route('posts.view', [
 'post' => $record,
 ]))

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
 
## [#](#customizing-the-save-notification)Customizing the save notification

When the record is successfully updated, a notification is dispatched to the user, which indicates the success of their action.

To customize the title of this notification, use the `successNotificationTitle()` method:

 use Filament\Actions\EditAction;
 
 EditAction::make()
 ->successNotificationTitle('User updated')

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

 use Filament\Actions\EditAction;
 use Filament\Notifications\Notification;
 
 EditAction::make()
 ->successNotification(
 Notification::make()
 ->success()
 ->title('User updated')
 ->body('The user has been saved successfully.'),
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

 use Filament\Actions\EditAction;
 
 EditAction::make()
 ->successNotification(null)

## [#](#lifecycle-hooks)Lifecycle hooks

Hooks may be used to execute code at various points within the action’s lifecycle, like before a form is saved.

There are several available hooks:

 use Filament\Actions\EditAction;
 
 EditAction::make()
 ->beforeFormFilled(function () {
 // Runs before the form fields are populated from the database.
 })
 ->afterFormFilled(function () {
 // Runs after the form fields are populated from the database.
 })
 ->beforeFormValidated(function () {
 // Runs before the form fields are validated when the form is saved.
 })
 ->afterFormValidated(function () {
 // Runs after the form fields are validated when the form is saved.
 })
 ->before(function () {
 // Runs before the form fields are saved to the database.
 })
 ->after(function () {
 // Runs after the form fields are saved to the database.
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
 
## [#](#halting-the-saving-process)Halting the saving process

At any time, you may call `$action->halt()` from inside a lifecycle hook or mutation method, which will halt the entire saving process:

 use App\Models\Post;
 use Filament\Actions\Action;
 use Filament\Actions\EditAction;
 use Filament\Notifications\Notification;
 
 EditAction::make()
 ->before(function (EditAction $action, Post $record) {
 if (! $record->team->subscribed()) {
 Notification::make()
 ->warning()
 ->title('You don\'t have an active subscription!')
 ->body('Choose a plan to continue.')
 ->persistent()
 ->actions([
 Action::make('subscribe')
 ->button()
 ->url(route('subscribe'), shouldOpenInNewTab: true),
 ])
 ->send();
 
 $action->halt();
 }
 })

If you’d like the action modal to close too, you can completely `cancel()` the action instead of halting it:

 $action->cancel();

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/actions/docs/05-edit.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)