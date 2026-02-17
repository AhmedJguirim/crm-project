# Source: https://filamentphp.com/docs/4.x/infolists/icon-entry

Infolists 

# Icon entry 

## [#](#introduction)Introduction

Icon entries render an [icon](../styling/icons) representing the state of the entry:

 use Filament\Infolists\Components\IconEntry;
 use Filament\Support\Icons\Heroicon;
 
 IconEntry::make('status')
 ->icon(fn (string $state): Heroicon => match ($state) {
 'draft' => Heroicon::OutlinedPencil,
 'reviewing' => Heroicon::OutlinedClock,
 'published' => Heroicon::OutlinedCheckCircle,
 })

The `icon()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Icon entry](/docs/4.x/images/light/infolists/entries/icon/simple.jpg) ![Icon entry](/docs/4.x/images/dark/infolists/entries/icon/simple.jpg)

## [#](#customizing-the-color)Customizing the color

You may change the [color](../styling/colors) of the icon, using the `color()` method:

 use Filament\Infolists\Components\IconEntry;
 
 IconEntry::make('status')
 ->color('success')

By passing a function to `color()`, you can customize the color based on the state of the entry:

 use Filament\Infolists\Components\IconEntry;
 
 IconEntry::make('status')
 ->color(fn (string $state): string => match ($state) {
 'draft' => 'info',
 'reviewing' => 'warning',
 'published' => 'success',
 default => 'gray',
 })

The `color()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Icon entry with color](/docs/4.x/images/light/infolists/entries/icon/color.jpg) ![Icon entry with color](/docs/4.x/images/dark/infolists/entries/icon/color.jpg)

## [#](#customizing-the-size)Customizing the size

The default icon size is `IconSize::Large`, but you may customize the size to be either `IconSize::ExtraSmall`, `IconSize::Small`, `IconSize::Medium`, `IconSize::ExtraLarge` or `IconSize::TwoExtraLarge`:

 use Filament\Infolists\Components\IconEntry;
 use Filament\Support\Enums\IconSize;
 
 IconEntry::make('status')
 ->size(IconSize::Medium)

As well as allowing a static value, the `size()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Medium-sized icon entry](/docs/4.x/images/light/infolists/entries/icon/medium.jpg) ![Medium-sized icon entry](/docs/4.x/images/dark/infolists/entries/icon/medium.jpg)

## [#](#handling-booleans)Handling booleans

Icon entries can display a check or “X” icon based on the state of the entry, either true or false, using the `boolean()` method:

 use Filament\Infolists\Components\IconEntry;
 
 IconEntry::make('is_featured')
 ->boolean()

> If this attribute in the model class is already cast as a `bool` or `boolean`, Filament is able to detect this, and you do not need to use `boolean()` manually.

![Icon entry to display a boolean](/docs/4.x/images/light/infolists/entries/icon/boolean.jpg) ![Icon entry to display a boolean](/docs/4.x/images/dark/infolists/entries/icon/boolean.jpg)

Optionally, you may pass a boolean value to control if the icon should be boolean or not:

 use Filament\Infolists\Components\IconEntry;
 
 IconEntry::make('is_featured')
 ->boolean(FeatureFlag::active())

As well as allowing a static value, the `boolean()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
### [#](#customizing-the-boolean-icons)Customizing the boolean icons

You may customize the [icon](../styling/icons) representing each state:

 use Filament\Infolists\Components\IconEntry;
 use Filament\Support\Icons\Heroicon;
 
 IconEntry::make('is_featured')
 ->boolean()
 ->trueIcon(Heroicon::OutlinedCheckBadge)
 ->falseIcon(Heroicon::OutlinedXMark)

As well as allowing static values, the `trueIcon()` and `falseIcon()` methods also accept functions to dynamically calculate them. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Icon entry to display a boolean with custom icons](/docs/4.x/images/light/infolists/entries/icon/boolean-icon.jpg) ![Icon entry to display a boolean with custom icons](/docs/4.x/images/dark/infolists/entries/icon/boolean-icon.jpg)

### [#](#customizing-the-boolean-colors)Customizing the boolean colors

You may customize the icon [color](../styling/colors) representing each state:

 use Filament\Infolists\Components\IconEntry;
 
 IconEntry::make('is_featured')
 ->boolean()
 ->trueColor('info')
 ->falseColor('warning')

As well as allowing static values, the `trueColor()` and `falseColor()` methods also accept functions to dynamically calculate them. You can inject various utilities into the functions as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Icon entry to display a boolean with custom colors](/docs/4.x/images/light/infolists/entries/icon/boolean-color.jpg) ![Icon entry to display a boolean with custom colors](/docs/4.x/images/dark/infolists/entries/icon/boolean-color.jpg)

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/infolists/docs/03-icon-entry.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)