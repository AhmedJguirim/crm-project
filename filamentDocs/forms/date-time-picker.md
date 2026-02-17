# Source: https://filamentphp.com/docs/4.x/forms/date-time-picker

Forms 

# Date-time picker 

## [#](#introduction)Introduction

The date-time picker provides an interactive interface for selecting a date and/or a time.

 use Filament\Forms\Components\DatePicker;
 use Filament\Forms\Components\DateTimePicker;
 use Filament\Forms\Components\TimePicker;
 
 DateTimePicker::make('published_at')
 DatePicker::make('date_of_birth')
 TimePicker::make('alarm_at')

![Date time pickers](/docs/4.x/images/light/forms/fields/date-time-picker/simple.jpg) ![Date time pickers](/docs/4.x/images/dark/forms/fields/date-time-picker/simple.jpg)

## [#](#customizing-the-storage-format)Customizing the storage format

You may customize the format of the field when it is saved in your database, using the `format()` method. This accepts a string date format, using [PHP date formatting tokens](https://www.php.net/manual/en/datetime.format.php):

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->format('d/m/Y')

As well as allowing a static value, the `format()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#disabling-the-seconds-input)Disabling the seconds input

When using the time picker, you may disable the seconds input using the `seconds(false)` method:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('published_at')
 ->seconds(false)

As well as allowing a static value, the `seconds()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Date time picker without seconds](/docs/4.x/images/light/forms/fields/date-time-picker/without-seconds.jpg) ![Date time picker without seconds](/docs/4.x/images/dark/forms/fields/date-time-picker/without-seconds.jpg)

## [#](#timezones)Timezones

If you’d like users to be able to manage dates in their own timezone, you can use the `timezone()` method:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('published_at')
 ->timezone('America/New_York')

While dates will still be stored using the app’s configured timezone, the date will now load in the new timezone, and it will be converted back when the form is saved.

As well as allowing a static value, the `timezone()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
If you do not pass a `timezone()` to the component, it will use Filament’s default timezone. You can set Filament’s default timezone using the `FilamentTimezone::set()` method in the `boot()` method of a service provider such as `AppServiceProvider`:

 use Filament\Support\Facades\FilamentTimezone;
 
 public function boot(): void
 {
 FilamentTimezone::set('America/New_York');
 }

This is useful if you want to set a default timezone for all date-time pickers in your application. It is also used in other places where timezones are used in Filament.

NOTE

Filament’s default timezone will only apply when the field stores a time. If the field stores a date only (`DatePicker` instead of `DateTimePicker` or `TimePicker`), the timezone will not be applied. This is to prevent timezone shifts when storing dates without times.

## [#](#enabling-the-javascript-date-picker)Enabling the JavaScript date picker

By default, Filament uses the native HTML5 date picker. You may enable a more customizable JavaScript date picker using the `native(false)` method:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->native(false)

As well as allowing a static value, the `native()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![JavaScript-based date time picker](/docs/4.x/images/light/forms/fields/date-time-picker/javascript.jpg) ![JavaScript-based date time picker](/docs/4.x/images/dark/forms/fields/date-time-picker/javascript.jpg)

NOTE

The JavaScript date picker does not support full keyboard input in the same way that the native date picker does. If you require full keyboard input, you should use the native date picker.

### [#](#customizing-the-display-format)Customizing the display format

You may customize the display format of the field, separately from the format used when it is saved in your database. For this, use the `displayFormat()` method, which also accepts a string date format, using [PHP date formatting tokens](https://www.php.net/manual/en/datetime.format.php):

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->native(false)
 ->displayFormat('d/m/Y')

As well as allowing a static value, the `displayFormat()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Date time picker with custom display format](/docs/4.x/images/light/forms/fields/date-time-picker/display-format.jpg) ![Date time picker with custom display format](/docs/4.x/images/dark/forms/fields/date-time-picker/display-format.jpg)

You may also configure the locale that is used when rendering the display, if you want to use different locale from your app config. For this, you can use the `locale()` method:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->native(false)
 ->displayFormat('d F Y')
 ->locale('fr')

As well as allowing a static value, the `locale()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#configuring-the-time-input-intervals)Configuring the time input intervals

You may customize the input interval for increasing/decreasing the hours/minutes /seconds using the `hoursStep()` , `minutesStep()` or `secondsStep()` methods:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('published_at')
 ->native(false)
 ->hoursStep(2)
 ->minutesStep(15)
 ->secondsStep(10)

As well as allowing static values, the `hoursStep()`, `minutesStep()`, and `secondsStep()` methods also accept functions to dynamically calculate them. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#configuring-the-first-day-of-the-week)Configuring the first day of the week

In some countries, the first day of the week is not Monday. To customize the first day of the week in the date picker, use the `firstDayOfWeek()` method on the component. 0 to 7 are accepted values, with Monday as 1 and Sunday as 7 or 0:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('published_at')
 ->native(false)
 ->firstDayOfWeek(7)

As well as allowing a static value, the `firstDayOfWeek()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Date time picker where the week starts on Sunday](/docs/4.x/images/light/forms/fields/date-time-picker/week-starts-on-sunday.jpg) ![Date time picker where the week starts on Sunday](/docs/4.x/images/dark/forms/fields/date-time-picker/week-starts-on-sunday.jpg)

There are additionally convenient helper methods to set the first day of the week more semantically:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('published_at')
 ->native(false)
 ->weekStartsOnMonday()
 
 DateTimePicker::make('published_at')
 ->native(false)
 ->weekStartsOnSunday()

### [#](#disabling-specific-dates)Disabling specific dates

To prevent specific dates from being selected:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('date')
 ->native(false)
 ->disabledDates(['2000-01-03', '2000-01-15', '2000-01-20'])

As well as allowing a static value, the `disabledDates()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Date time picker where dates are disabled](/docs/4.x/images/light/forms/fields/date-time-picker/disabled-dates.jpg) ![Date time picker where dates are disabled](/docs/4.x/images/dark/forms/fields/date-time-picker/disabled-dates.jpg)

### [#](#closing-the-picker-when-a-date-is-selected)Closing the picker when a date is selected

To close the picker when a date is selected, you can use the `closeOnDateSelection()` method:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('date')
 ->native(false)
 ->closeOnDateSelection()

Optionally, you may pass a boolean value to control if the input should close when a date is selected or not:

 use Filament\Forms\Components\DateTimePicker;
 
 DateTimePicker::make('date')
 ->native(false)
 ->closeOnDateSelection(FeatureFlag::active())

As well as allowing a static value, the `closeOnDateSelection()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#autocompleting-dates-with-a-datalist)Autocompleting dates with a datalist

Unless you’re using the [JavaScript date picker](#enabling-the-javascript-date-picker), you may specify [datalist](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/datalist) options for a date picker using the `datalist()` method:

 use Filament\Forms\Components\TimePicker;
 
 TimePicker::make('appointment_at')
 ->datalist([
 '09:00',
 '09:30',
 '10:00',
 '10:30',
 '11:00',
 '11:30',
 '12:00',
 ])

Datalists provide autocomplete options to users when they use the picker. However, these are purely recommendations, and the user is still able to type any value into the input. If you’re looking to strictly limit users to a set of predefined options, check out the [select field](select).

As well as allowing a static value, the `datalist()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#focusing-a-default-calendar-date)Focusing a default calendar date

By default, if the field has no state, opening the calendar panel will open the calendar at the current date. This might not be convenient for situations where you want to open the calendar on a specific date instead. You can use the `defaultFocusedDate()` to set a default focused date on the calendar:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('custom_starts_at')
 ->native(false)
 ->placeholder(now()->startOfMonth())
 ->defaultFocusedDate(now()->startOfMonth())

As well as allowing a static value, the `defaultFocusedDate()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#adding-affix-text-aside-the-field)Adding affix text aside the field

You may place text before and after the input using the `prefix()` and `suffix()` methods:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date')
 ->prefix('Starts')
 ->suffix('at midnight')

As well as allowing static values, the `prefix()` and `suffix()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Date time picker with affixes](/docs/4.x/images/light/forms/fields/date-time-picker/affix.jpg) ![Date time picker with affixes](/docs/4.x/images/dark/forms/fields/date-time-picker/affix.jpg)

### [#](#using-icons-as-affixes)Using icons as affixes

You may place an [icon](../styling/icons) before and after the input using the `prefixIcon()` and `suffixIcon()` methods:

 use Filament\Forms\Components\TimePicker;
 use Filament\Support\Icons\Heroicon;
 
 TimePicker::make('at')
 ->prefixIcon(Heroicon::Play)

As well as allowing static values, the `prefixIcon()` and `suffixIcon()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Date time picker with prefix icon](/docs/4.x/images/light/forms/fields/date-time-picker/prefix-icon.jpg) ![Date time picker with prefix icon](/docs/4.x/images/dark/forms/fields/date-time-picker/prefix-icon.jpg)

#### [#](#setting-the-affix-icons-color)Setting the affix icon’s color

Affix icons are gray by default, but you may set a different color using the `prefixIconColor()` and `suffixIconColor()` methods:

 use Filament\Forms\Components\TimePicker;
 use Filament\Support\Icons\Heroicon;
 
 TimePicker::make('at')
 ->prefixIcon(Heroicon::CheckCircle)
 ->prefixIconColor('success')

As well as allowing static values, the `prefixIconColor()` and `suffixIconColor()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#making-the-field-read-only)Making the field read-only

Not to be confused with [disabling the field](overview#disabling-a-field), you may make the field “read-only” using the `readonly()` method:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->readonly()

Please note that this setting is only enforced on native date pickers. If you’re using the [JavaScript date picker](#enabling-the-javascript-date-picker), you’ll need to use [`disabled()`](overview#disabling-a-field).

There are a few differences, compared to [`disabled()`](overview#disabling-a-field):

 * When using `readOnly()`, the field will still be sent to the server when the form is submitted. It can be mutated with the browser console, or via JavaScript. You can use [`saved(false)`](overview#preventing-a-field-from-being-saved) to prevent this.
 * There are no styling changes, such as less opacity, when using `readOnly()`.
 * The field is still focusable when using `readOnly()`.

Optionally, you may pass a boolean value to control if the field should be read-only or not:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->readOnly(FeatureFlag::active())

As well as allowing a static value, the `readOnly()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#date-time-picker-validation)Date-time picker validation

As well as all rules listed on the [validation](validation) page, there are additional rules that are specific to date-time pickers.

### [#](#max-date--min-date-validation)Max date / min date validation

You may restrict the minimum and maximum date that can be selected with the picker. The `minDate()` and `maxDate()` methods accept a `DateTime` instance (e.g. `Carbon`), or a string:

 use Filament\Forms\Components\DatePicker;
 
 DatePicker::make('date_of_birth')
 ->native(false)
 ->minDate(now()->subYears(150))
 ->maxDate(now())

As well as allowing static values, the `minDate()` and `maxDate()` methods also accept functions to dynamically calculate them. If the functions return `null`, the validation rule is not applied. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/08-date-time-picker.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)