# Source: https://filamentphp.com/docs/4.x/actions/view

Actions 

# View action 

## [#](#introduction)Introduction

Filament includes an action that is able to view Eloquent records. When the trigger button is clicked, a modal will open with information inside. Filament uses form fields to structure this information. All form fields are disabled, so they are not editable by the user. You may use it like so:

 use Filament\Actions\ViewAction;
 use Filament\Forms\Components\TextInput;
 
 ViewAction::make()
 ->schema([
 TextInput::make('title')
 ->required()
 ->maxLength(255),
 // ...
 ])

## [#](#customizing-data-before-filling-the-form)Customizing data before filling the form

You may wish to modify the data from a record before it is filled into the form. To do this, you may use the `mutateRecordDataUsing()` method to modify the `$data` array, and return the modified version before it is filled into the form:

 use Filament\Actions\ViewAction;
 
 ViewAction::make()
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
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/actions/docs/06-view.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)