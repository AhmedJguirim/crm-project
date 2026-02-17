# Source: https://filamentphp.com/docs/4.x/forms/toggle-buttons

Forms 

# Toggle buttons 

## [#](#introduction)Introduction

The toggle buttons input provides a group of buttons for selecting a single value, or multiple values, from a list of predefined options:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('status')
 ->options([
 'draft' => 'Draft',
 'scheduled' => 'Scheduled',
 'published' => 'Published'
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
 
![Toggle buttons](/docs/4.x/images/light/forms/fields/toggle-buttons/simple.jpg) ![Toggle buttons](/docs/4.x/images/dark/forms/fields/toggle-buttons/simple.jpg)

## [#](#changing-the-color-of-option-buttons)Changing the color of option buttons

You can change the [color](../styling/colors) of the option buttons using the `colors()` method. Each key in the array should correspond to an option value:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('status')
 ->options([
 'draft' => 'Draft',
 'scheduled' => 'Scheduled',
 'published' => 'Published'
 ])
 ->colors([
 'draft' => 'info',
 'scheduled' => 'warning',
 'published' => 'success',
 ])

If you are using an enum for the options, you can use the [`HasColor` interface](../advanced/enums#enum-colors) to define colors instead.

As well as allowing a static array, the `colors()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Toggle buttons with different colors](/docs/4.x/images/light/forms/fields/toggle-buttons/colors.jpg) ![Toggle buttons with different colors](/docs/4.x/images/dark/forms/fields/toggle-buttons/colors.jpg)

## [#](#adding-icons-to-option-buttons)Adding icons to option buttons

You can add [icon](../styling/icons) to the option buttons using the `icons()` method. Each key in the array should correspond to an option value, and the value may be any valid [icon](../styling/icons):

 use Filament\Forms\Components\ToggleButtons;
 use Filament\Support\Icons\Heroicon;
 
 ToggleButtons::make('status')
 ->options([
 'draft' => 'Draft',
 'scheduled' => 'Scheduled',
 'published' => 'Published'
 ])
 ->icons([
 'draft' => Heroicon::OutlinedPencil,
 'scheduled' => Heroicon::OutlinedClock,
 'published' => Heroicon::OutlinedCheckCircle,
 ])

As well as allowing a static array, the `icons()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
If you are using an enum for the options, you can use the [`HasIcon` interface](../advanced/enums#enum-icons) to define icons instead.

![Toggle buttons with icons](/docs/4.x/images/light/forms/fields/toggle-buttons/icons.jpg) ![Toggle buttons with icons](/docs/4.x/images/dark/forms/fields/toggle-buttons/icons.jpg)

If you want to display only icons, you can use `hiddenButtonLabels()` to hide the option labels.

## [#](#boolean-options)Boolean options

If you want a simple boolean toggle button group, with “Yes” and “No” options, you can use the `boolean()` method:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean()

The options will have [colors](#changing-the-color-of-option-buttons) and [icons](#adding-icons-to-option-buttons) set up automatically, but you can override these with `colors()` or `icons()`.

![Boolean toggle buttons](/docs/4.x/images/light/forms/fields/toggle-buttons/boolean.jpg) ![Boolean toggle buttons](/docs/4.x/images/dark/forms/fields/toggle-buttons/boolean.jpg)

To customize the “Yes” label, you can use the `trueLabel` argument on the `boolean()` method:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean(trueLabel: 'Absolutely!')

To customize the “No” label, you can use the `falseLabel` argument on the `boolean()` method:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean(falseLabel: 'Not at all!')

## [#](#positioning-the-options-inline-with-each-other)Positioning the options inline with each other

You may wish to display the buttons `inline()` with each other:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean()
 ->inline()

![Inline toggle buttons](/docs/4.x/images/light/forms/fields/toggle-buttons/inline.jpg) ![Inline toggle buttons](/docs/4.x/images/dark/forms/fields/toggle-buttons/inline.jpg)

Optionally, you may pass a boolean value to control if the buttons should be inline or not:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean()
 ->inline(FeatureFlag::active())

As well as allowing a static value, the `inline()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#grouping-option-buttons)Grouping option buttons

You may wish to group option buttons together so they are more compact, using the `grouped()` method. This also makes them appear horizontally inline with each other:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean()
 ->grouped()

![Grouped toggle buttons](/docs/4.x/images/light/forms/fields/toggle-buttons/grouped.jpg) ![Grouped toggle buttons](/docs/4.x/images/dark/forms/fields/toggle-buttons/grouped.jpg)

Optionally, you may pass a boolean value to control if the buttons should be grouped or not:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('feedback')
 ->label('Like this post?')
 ->boolean()
 ->grouped(FeatureFlag::active())

As well as allowing a static value, the `grouped()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#selecting-multiple-buttons)Selecting multiple buttons

The `multiple()` method on the `ToggleButtons` component allows you to select multiple values from the list of options:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('technologies')
 ->multiple()
 ->options([
 'tailwind' => 'Tailwind CSS',
 'alpine' => 'Alpine.js',
 'laravel' => 'Laravel',
 'livewire' => 'Laravel Livewire',
 ])

![Multiple toggle buttons selected](/docs/4.x/images/light/forms/fields/toggle-buttons/multiple.jpg) ![Multiple toggle buttons selected](/docs/4.x/images/dark/forms/fields/toggle-buttons/multiple.jpg)

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

Optionally, you may pass a boolean value to control if the buttons should allow multiple selections or not:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('technologies')
 ->multiple(FeatureFlag::active())
 ->options([
 'tailwind' => 'Tailwind CSS',
 'alpine' => 'Alpine.js',
 'laravel' => 'Laravel',
 'livewire' => 'Laravel Livewire',
 ])

As well as allowing a static value, the `multiple()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#splitting-options-into-columns)Splitting options into columns

You may split options into columns by using the `columns()` method:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('technologies')
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
 
![Toggle buttons with 2 columns](/docs/4.x/images/light/forms/fields/toggle-buttons/columns.jpg) ![Toggle buttons with 2 columns](/docs/4.x/images/dark/forms/fields/toggle-buttons/columns.jpg)

This method accepts the same options as the `columns()` method of the [grid](../schemas/layouts#grid-system). This allows you to responsively customize the number of columns at various breakpoints.

### [#](#setting-the-grid-direction)Setting the grid direction

By default, when you arrange buttons into columns, they will be listed in order vertically. If you’d like to list them horizontally, you may use the `gridDirection(GridDirection::Row)` method:

 use Filament\Forms\Components\ToggleButtons;
 use Filament\Support\Enums\GridDirection;
 
 ToggleButtons::make('technologies')
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
 
![Toggle buttons with 2 rows](/docs/4.x/images/light/forms/fields/toggle-buttons/rows.jpg) ![Toggle buttons with 2 rows](/docs/4.x/images/dark/forms/fields/toggle-buttons/rows.jpg)

## [#](#disabling-specific-options)Disabling specific options

You can disable specific options using the `disableOptionWhen()` method. It accepts a closure, in which you can check if the option with a specific `$value` should be disabled:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('status')
 ->options([
 'draft' => 'Draft',
 'scheduled' => 'Scheduled',
 'published' => 'Published',
 ])
 ->disableOptionWhen(fn (string $value): bool => $value === 'published')

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
 
![Toggle buttons with disabled option](/docs/4.x/images/light/forms/fields/toggle-buttons/disabled-option.jpg) ![Toggle buttons with disabled option](/docs/4.x/images/dark/forms/fields/toggle-buttons/disabled-option.jpg)

If you want to retrieve the options that have not been disabled, e.g. for validation purposes, you can do so using `getEnabledOptions()`:

 use Filament\Forms\Components\ToggleButtons;
 
 ToggleButtons::make('status')
 ->options([
 'draft' => 'Draft',
 'scheduled' => 'Scheduled',
 'published' => 'Published',
 ])
 ->disableOptionWhen(fn (string $value): bool => $value === 'published')
 ->in(fn (ToggleButtons $component): array => array_keys($component->getEnabledOptions()))

For more information about the `in()` function, please see the [Validation documentation](validation#in).

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/18-toggle-buttons.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)