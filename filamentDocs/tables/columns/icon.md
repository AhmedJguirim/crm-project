# Source: https://filamentphp.com/docs/4.x/tables/columns/icon

Tables \- Columns 

# Icon column 

## [#](#introduction)Introduction

Icon columns render an [icon](../../styling/icons) representing the state of the column:

 use Filament\Tables\Columns\IconColumn;
 use Filament\Support\Icons\Heroicon;
 
 IconColumn::make('status')
 ->icon(fn (string $state): Heroicon => match ($state) {
 'draft' => Heroicon::OutlinedPencil,
 'reviewing' => Heroicon::OutlinedClock,
 'published' => Heroicon::OutlinedCheckCircle,
 })

The `icon()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Icon column](/docs/4.x/images/light/tables/columns/icon/simple.jpg) ![Icon column](/docs/4.x/images/dark/tables/columns/icon/simple.jpg)

## [#](#customizing-the-color)Customizing the color

You may change the [color](../../styling/colors) of the icon, using the `color()` method:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('status')
 ->color('success')

By passing a function to `color()`, you can customize the color based on the state of the column:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('status')
 ->color(fn (string $state): string => match ($state) {
 'draft' => 'info',
 'reviewing' => 'warning',
 'published' => 'success',
 default => 'gray',
 })

The `color()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Icon column with color](/docs/4.x/images/light/tables/columns/icon/color.jpg) ![Icon column with color](/docs/4.x/images/dark/tables/columns/icon/color.jpg)

## [#](#customizing-the-size)Customizing the size

The default icon size is `IconSize::Large`, but you may customize the size to be either `IconSize::ExtraSmall`, `IconSize::Small`, `IconSize::Medium`, `IconSize::ExtraLarge` or `IconSize::TwoExtraLarge`:

 use Filament\Tables\Columns\IconColumn;
 use Filament\Support\Enums\IconSize;
 
 IconColumn::make('status')
 ->size(IconSize::Medium)

As well as allowing a static value, the `size()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Medium-sized icon column](/docs/4.x/images/light/tables/columns/icon/medium.jpg) ![Medium-sized icon column](/docs/4.x/images/dark/tables/columns/icon/medium.jpg)

## [#](#handling-booleans)Handling booleans

Icon columns can display a check or “X” icon based on the state of the column, either true or false, using the `boolean()` method:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('is_featured')
 ->boolean()

> If this attribute in the model class is already cast as a `bool` or `boolean`, Filament is able to detect this, and you do not need to use `boolean()` manually.

![Icon column to display a boolean](/docs/4.x/images/light/tables/columns/icon/boolean.jpg) ![Icon column to display a boolean](/docs/4.x/images/dark/tables/columns/icon/boolean.jpg)

Optionally, you may pass a boolean value to control if the icon should be boolean or not:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('is_featured')
 ->boolean(FeatureFlag::active())

As well as allowing a static value, the `boolean()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#customizing-the-boolean-icons)Customizing the boolean icons

You may customize the [icon](../../styling/icons) representing each state:

 use Filament\Tables\Columns\IconColumn;
 use Filament\Support\Icons\Heroicon;
 
 IconColumn::make('is_featured')
 ->boolean()
 ->trueIcon(Heroicon::OutlinedCheckBadge)
 ->falseIcon(Heroicon::OutlinedXMark)

As well as allowing static values, the `trueIcon()` and `falseIcon()` methods also accept functions to dynamically calculate them. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Icon column to display a boolean with custom icons](/docs/4.x/images/light/tables/columns/icon/boolean-icon.jpg) ![Icon column to display a boolean with custom icons](/docs/4.x/images/dark/tables/columns/icon/boolean-icon.jpg)

### [#](#customizing-the-boolean-colors)Customizing the boolean colors

You may customize the icon [color](../../styling/colors) representing each state:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('is_featured')
 ->boolean()
 ->trueColor('info')
 ->falseColor('warning')

As well as allowing static values, the `trueColor()` and `falseColor()` methods also accept functions to dynamically calculate them. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Icon column to display a boolean with custom colors](/docs/4.x/images/light/tables/columns/icon/boolean-color.jpg) ![Icon column to display a boolean with custom colors](/docs/4.x/images/dark/tables/columns/icon/boolean-color.jpg)

## [#](#wrapping-multiple-icons)Wrapping multiple icons

When displaying multiple icons, they can be set to wrap if they can’t fit on one line, using `wrap()`:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('icon')
 ->wrap()

TIP

The “width” for wrapping is affected by the column label, so you may need to use a shorter or hidden label to wrap more tightly.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/03-icon.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)