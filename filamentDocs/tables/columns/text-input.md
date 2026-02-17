# Source: https://filamentphp.com/docs/4.x/tables/columns/text-input

Tables \- Columns 

# Text input column 

## [#](#introduction)Introduction

The text input column allows you to render a text input inside the table, which can be used to update that database record without needing to open a new page or a modal:

 use Filament\Tables\Columns\TextInputColumn;
 
 TextInputColumn::make('email')

![Text input column](/docs/4.x/images/light/tables/columns/text-input/simple.jpg) ![Text input column](/docs/4.x/images/dark/tables/columns/text-input/simple.jpg)

## [#](#validation)Validation

You can validate the input by passing any [Laravel validation rules](https://laravel.com/docs/validation#available-validation-rules) in an array:

 use Filament\Tables\Columns\TextInputColumn;
 
 TextInputColumn::make('name')
 ->rules(['required', 'max:255'])

## [#](#customizing-the-html-input-type)Customizing the HTML input type

You may use the `type()` method to pass a custom [HTML input type](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input#input_types):

 use Filament\Tables\Columns\TextInputColumn;
 
 TextInputColumn::make('background_color')->type('color')

## [#](#lifecycle-hooks)Lifecycle hooks

Hooks may be used to execute code at various points within the input’s lifecycle:

 TextInputColumn::make()
 ->beforeStateUpdated(function ($record, $state) {
 // Runs before the state is saved to the database.
 })
 ->afterStateUpdated(function ($record, $state) {
 // Runs after the state is saved to the database.
 })

## [#](#adding-affix-text-aside-the-field)Adding affix text aside the field

You may place text before and after the input using the `prefix()` and `suffix()` methods:

 use Filament\Tables\Columns\TextInputColumn;
 
 TextInputColumn::make('domain')
 ->prefix('https://')
 ->suffix('.com')

As well as allowing static values, the `prefix()` and `suffix()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#using-icons-as-affixes)Using icons as affixes

You may place an [icon](../../styling/icons) before and after the input using the `prefixIcon()` and `suffixIcon()` methods:

 use Filament\Tables\Columns\TextInputColumn;
 use Filament\Support\Icons\Heroicon;
 
 TextInputColumn::make('domain')
 ->prefixIcon(Heroicon::GlobeAlt)
 ->suffixIcon(Heroicon::CheckCircle)

As well as allowing static values, the `prefixIcon()` and `suffixIcon()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#setting-the-affix-icons-color)Setting the affix icon’s color

Affix icons are gray by default, but you may set a different color using the `prefixIconColor()` and `suffixIconColor()` methods:

 use Filament\Tables\Columns\TextInputColumn;
 use Filament\Support\Icons\Heroicon;
 
 TextInputColumn::make('status')
 ->suffixIcon(Heroicon::CheckCircle)
 ->suffixIconColor('success')

As well as allowing static values, the `prefixIconColor()` and `suffixIconColor()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/08-text-input.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)