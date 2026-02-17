# Source: https://filamentphp.com/docs/4.x/forms/checkbox-list

Forms 

# Checkbox list 

## [#](#introduction)Introduction

The checkbox list component allows you to select multiple values from a list of predefined options:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 'tailwind' => 'Tailwind CSS',
 'alpine' => 'Alpine.js',
 'laravel' => 'Laravel',
 'livewire' => 'Laravel Livewire',
 ])

As well as allowing a static array, the `options()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Checkbox list](/docs/4.x/images/light/forms/fields/checkbox-list/simple.jpg) ![Checkbox list](/docs/4.x/images/dark/forms/fields/checkbox-list/simple.jpg)

These options are returned in JSON format. If you’re saving them using Eloquent, you should be sure to add an `array` [cast](https://laravel.com/docs/eloquent-mutators#array-and-json-casting) to the model property:

 use Illuminate\Database\Eloquent\Model;
 
 class App extends Model
 {
 /**
 * @return array<string, string>
 */
 protected function casts(): array
 {
 return [
 'technologies' => 'array',
 ];
 }
 
 // ...
 }

## [#](#setting-option-descriptions)Setting option descriptions

You can optionally provide descriptions to each option using the `descriptions()` method. This method accepts an array of plain text strings, or instances of `Illuminate\Support\HtmlString` or `Illuminate\Contracts\Support\Htmlable`. This allows you to render HTML, or even markdown, in the descriptions:

 use Filament\Forms\Components\CheckboxList;
 use Illuminate\Support\HtmlString;
 
 CheckboxList::make('technologies')
 ->options([
 'tailwind' => 'Tailwind CSS',
 'alpine' => 'Alpine.js',
 'laravel' => 'Laravel',
 'livewire' => 'Laravel Livewire',
 ])
 ->descriptions([
 'tailwind' => 'A utility-first CSS framework for rapidly building modern websites without ever leaving your HTML.',
 'alpine' => new HtmlString('A rugged, minimal tool for composing behavior <strong>directly in your markup</strong>.'),
 'laravel' => str('A **web application** framework with expressive, elegant syntax.')->inlineMarkdown()->toHtmlString(),
 'livewire' => 'A full-stack framework for Laravel building dynamic interfaces simple, without leaving the comfort of Laravel.',
 ])

As well as allowing a static array, the `descriptions()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Checkbox list with option descriptions](/docs/4.x/images/light/forms/fields/checkbox-list/option-descriptions.jpg) ![Checkbox list with option descriptions](/docs/4.x/images/dark/forms/fields/checkbox-list/option-descriptions.jpg)

NOTE

Be sure to use the same `key` in the descriptions array as the `key` in the option array so the right description matches the right option.

## [#](#splitting-options-into-columns)Splitting options into columns

You may split options into columns by using the `columns()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->columns(2)

As well as allowing a static value, the `columns()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Checkbox list with 2 columns](/docs/4.x/images/light/forms/fields/checkbox-list/columns.jpg) ![Checkbox list with 2 columns](/docs/4.x/images/dark/forms/fields/checkbox-list/columns.jpg)

This method accepts the same options as the `columns()` method of the [grid](../schemas/layouts#grid-system). This allows you to responsively customize the number of columns at various breakpoints.

### [#](#setting-the-grid-direction)Setting the grid direction

By default, when you arrange checkboxes into columns, they will be listed in order vertically. If you’d like to list them horizontally, you may use the `gridDirection(GridDirection::Row)` method:

 use Filament\Forms\Components\CheckboxList;
 use Filament\Support\Enums\GridDirection;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->columns(2)
 ->gridDirection(GridDirection::Row)

As well as allowing a static value, the `gridDirection()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Checkbox list with 2 rows](/docs/4.x/images/light/forms/fields/checkbox-list/rows.jpg) ![Checkbox list with 2 rows](/docs/4.x/images/dark/forms/fields/checkbox-list/rows.jpg)

## [#](#searching-options)Searching options

You may enable a search input to allow easier access to many options, using the `searchable()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->searchable()

![Searchable checkbox list](/docs/4.x/images/light/forms/fields/checkbox-list/searchable.jpg) ![Searchable checkbox list](/docs/4.x/images/dark/forms/fields/checkbox-list/searchable.jpg)

Optionally, you may pass a boolean value to control if the options should be searchable or not:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->searchable(FeatureFlag::active())

As well as allowing a static value, the `searchable()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#bulk-toggling-checkboxes)Bulk toggling checkboxes

You may allow users to toggle all checkboxes at once using the `bulkToggleable()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->bulkToggleable()

![Bulk toggleable checkbox list](/docs/4.x/images/light/forms/fields/checkbox-list/bulk-toggleable.jpg) ![Bulk toggleable checkbox list](/docs/4.x/images/dark/forms/fields/checkbox-list/bulk-toggleable.jpg)

Optionally, you may pass a boolean value to control if the checkboxes should be bulk toggleable or not:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->bulkToggleable(FeatureFlag::active())

As well as allowing a static value, the `bulkToggleable()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#disabling-specific-options)Disabling specific options

You can disable specific options using the `disableOptionWhen()` method. It accepts a closure, in which you can check if the option with a specific `$value` should be disabled:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 'tailwind' => 'Tailwind CSS',
 'alpine' => 'Alpine.js',
 'laravel' => 'Laravel',
 'livewire' => 'Laravel Livewire',
 ])
 ->disableOptionWhen(fn (string $value): bool => $value === 'livewire')

You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Option label | `string | Illuminate\Contracts\Support\Htmlable` | `$label` | The label of the option to disable. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
Option value | `mixed` | `$value` | The value of the option to disable. 
 
If you want to retrieve the options that have not been disabled, e.g. for validation purposes, you can do so using `getEnabledOptions()`:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 'tailwind' => 'Tailwind CSS',
 'alpine' => 'Alpine.js',
 'laravel' => 'Laravel',
 'livewire' => 'Laravel Livewire',
 'heroicons' => 'SVG icons',
 ])
 ->disableOptionWhen(fn (string $value): bool => $value === 'heroicons')
 ->in(fn (CheckboxList $component): array => array_keys($component->getEnabledOptions()))

For more information about the `in()` function, please see the [Validation documentation](validation#in).

## [#](#allowing-html-in-the-option-labels)Allowing HTML in the option labels

By default, Filament will escape any HTML in the option labels. If you’d like to allow HTML, you can use the `allowHtml()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technology')
 ->options([
 'tailwind' => '<span class="text-blue-500">Tailwind</span>',
 'alpine' => '<span class="text-green-500">Alpine</span>',
 'laravel' => '<span class="text-red-500">Laravel</span>',
 'livewire' => '<span class="text-pink-500">Livewire</span>',
 ])
 ->searchable()
 ->allowHtml()

NOTE

Be aware that you will need to ensure that the HTML is safe to render, otherwise your application will be vulnerable to XSS attacks.

Optionally, you may pass a boolean value to control if the options should allow HTML or not:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technology')
 ->options([
 'tailwind' => '<span class="text-blue-500">Tailwind</span>',
 'alpine' => '<span class="text-green-500">Alpine</span>',
 'laravel' => '<span class="text-red-500">Laravel</span>',
 'livewire' => '<span class="text-pink-500">Livewire</span>',
 ])
 ->searchable()
 ->allowHtml(FeatureFlag::active())

As well as allowing a static value, the `allowHtml()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#integrating-with-an-eloquent-relationship)Integrating with an Eloquent relationship

> If you’re building a form inside your Livewire component, make sure you have set up the [form’s model](../components/form#setting-a-form-model). Otherwise, Filament doesn’t know which model to use to retrieve the relationship from.

You may employ the `relationship()` method of the `CheckboxList` to point to a `BelongsToMany` relationship. Filament will load the options from the relationship, and save them back to the relationship’s pivot table when the form is submitted. The `titleAttribute` is the name of a column that will be used to generate a label for each option:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->relationship(titleAttribute: 'name')

NOTE

When using `disabled()` with `relationship()`, ensure that `disabled()` is called before `relationship()`. This ensures that the `saved()` call from `disabled()` is not applied after the `relationship()` configuration:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->disabled()
 ->relationship(titleAttribute: 'name')

### [#](#customizing-the-relationship-query)Customizing the relationship query

You may customize the database query that retrieves options using the `modifyOptionsQueryUsing` parameter of the `relationship()` method:

 use Filament\Forms\Components\CheckboxList;
 use Illuminate\Database\Eloquent\Builder;
 
 CheckboxList::make('technologies')
 ->relationship(
 titleAttribute: 'name',
 modifyQueryUsing: fn (Builder $query) => $query->withTrashed(),
 )

The `modifyQueryUsing` argument can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Query | `Illuminate\Database\Eloquent\Builder` | `$query` | The Eloquent query builder to modify. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#customizing-the-relationship-option-labels)Customizing the relationship option labels

If you’d like to customize the label of each option, maybe to be more descriptive, or to concatenate a first and last name, you could use a virtual column in your database migration:

 $table->string('full_name')->virtualAs('concat(first_name, \' \', last_name)');

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('authors')
 ->relationship(titleAttribute: 'full_name')

Alternatively, you can use the `getOptionLabelFromRecordUsing()` method to transform an option’s Eloquent model into a label:

 use Filament\Forms\Components\CheckboxList;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
 
 CheckboxList::make('authors')
 ->relationship(
 modifyQueryUsing: fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name'),
 )
 ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->first_name} {$record->last_name}")

The `getOptionLabelFromRecordUsing()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record to get the option label for. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#customizing-the-relationship-option-descriptions)Customizing the relationship option descriptions

If you’d like to customize the description of each option, you can use the `getOptionDescriptionFromRecordUsing()` method to transform an option’s Eloquent model into a description:

 use Filament\Forms\Components\CheckboxList;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
 
 CheckboxList::make('authors')
 ->relationship(
 modifyQueryUsing: fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name'),
 )
 ->getOptionDescriptionFromRecordUsing(fn (Model $record) => $record->notes)

The `getOptionDescriptionFromRecordUsing()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record to get the option description for. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#saving-pivot-data-to-the-relationship)Saving pivot data to the relationship

If your pivot table has additional columns, you can use the `pivotData()` method to specify the data that should be saved in them:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('primaryTechnologies')
 ->relationship(name: 'technologies', titleAttribute: 'name')
 ->pivotData([
 'is_primary' => true,
 ])

As well as allowing a static value, the `pivotData()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#setting-a-custom-no-search-results-message)Setting a custom no search results message

When you’re using a searchable checkbox list, you may want to display a custom message when no search results are found. You can do this using the `noSearchResultsMessage()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->searchable()
 ->noSearchResultsMessage('No technologies found.')

As well as allowing a static value, the `noSearchResultsMessage()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#setting-a-custom-search-prompt)Setting a custom search prompt

When you’re using a searchable checkbox list, you may want to tweak the search input’s placeholder when the user has not yet entered a search term. You can do this using the `searchPrompt()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->searchable()
 ->searchPrompt('Search for a technology')

As well as allowing a static value, the `searchPrompt()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#tweaking-the-search-debounce)Tweaking the search debounce

By default, Filament will wait 1000 milliseconds (1 second) before searching for options when the user types in a searchable checkbox list. It will also wait 1000 milliseconds between searches if the user is continuously typing into the search input. You can change this using the `searchDebounce()` method:

 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->searchable()
 ->searchDebounce(500)

As well as allowing a static value, the `searchDebounce()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#customizing-the-checkbox-list-action-objects)Customizing the checkbox list action objects

This field uses action objects for easy customization of buttons within it. You can customize these buttons by passing a function to an action registration method. The function has access to the `$action` object, which you can use to [customize it](../actions/overview). The following methods are available to customize the actions:

 * `selectAllAction()`
 * `deselectAllAction()`

Here is an example of how you might customize an action:

 use Filament\Actions\Action;
 use Filament\Forms\Components\CheckboxList;
 
 CheckboxList::make('technologies')
 ->options([
 // ...
 ])
 ->selectAllAction(
 fn (Action $action) => $action->label('Select all technologies'),
 )

The action registration methods can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Action | `Filament\Actions\Action` | `$action` | The action object to customize. 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/06-checkbox-list.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)