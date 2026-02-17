# Source: https://filamentphp.com/docs/4.x/tables/columns/color

Tables \- Columns 

# Color column 

## [#](#introduction)Introduction

The color column allows you to show the color preview from a CSS color definition, typically entered using the [color picker field](../../forms/color-picker), in one of the supported formats (HEX, HSL, RGB, RGBA).

 use Filament\Tables\Columns\ColorColumn;
 
 ColorColumn::make('color')

![Color column](/docs/4.x/images/light/tables/columns/color/simple.jpg) ![Color column](/docs/4.x/images/dark/tables/columns/color/simple.jpg)

## [#](#allowing-the-color-to-be-copied-to-the-clipboard)Allowing the color to be copied to the clipboard

You may make the color copyable, such that clicking on the preview copies the CSS value to the clipboard, and optionally specify a custom confirmation message and duration in milliseconds. This feature only works when SSL is enabled for the app.

 use Filament\Tables\Columns\ColorColumn;
 
 ColorColumn::make('color')
 ->copyable()
 ->copyMessage('Copied!')
 ->copyMessageDuration(1500)

![Color column with a button to copy it](/docs/4.x/images/light/tables/columns/color/copyable.jpg) ![Color column with a button to copy it](/docs/4.x/images/dark/tables/columns/color/copyable.jpg)

Optionally, you may pass a boolean value to control if the text should be copyable or not:

 use Filament\Tables\Columns\ColorColumn;
 
 ColorColumn::make('color')
 ->copyable(FeatureFlag::active())

As well as allowing static values, the `copyable()`, `copyMessage()`, and `copyMessageDuration()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#wrapping-multiple-color-blocks)Wrapping multiple color blocks

Color blocks can be set to wrap if they can’t fit on one line, by setting `wrap()`:

 use Filament\Tables\Columns\ColorColumn;
 
 ColorColumn::make('color')
 ->wrap()

TIP

The “width” for wrapping is affected by the column label, so you may need to use a shorter or hidden label to wrap more tightly.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/05-color.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)