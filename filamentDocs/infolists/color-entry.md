# Source: https://filamentphp.com/docs/4.x/infolists/color-entry

Infolists 

# Color entry 

## [#](#introduction)Introduction

The color entry allows you to show the color preview from a CSS color definition, typically entered using the [color picker field](../forms/color-picker), in one of the supported formats (HEX, HSL, RGB, RGBA).

 use Filament\Infolists\Components\ColorEntry;
 
 ColorEntry::make('color')

![Color entry](/docs/4.x/images/light/infolists/entries/color/simple.jpg) ![Color entry](/docs/4.x/images/dark/infolists/entries/color/simple.jpg)

## [#](#allowing-the-color-to-be-copied-to-the-clipboard)Allowing the color to be copied to the clipboard

You may make the color copyable, such that clicking on the preview copies the CSS value to the clipboard, and optionally specify a custom confirmation message and duration in milliseconds. This feature only works when SSL is enabled for the app.

 use Filament\Infolists\Components\ColorEntry;
 
 ColorEntry::make('color')
 ->copyable()
 ->copyMessage('Copied!')
 ->copyMessageDuration(1500)

![Color entry with a button to copy it](/docs/4.x/images/light/infolists/entries/color/copyable.jpg) ![Color entry with a button to copy it](/docs/4.x/images/dark/infolists/entries/color/copyable.jpg)

Optionally, you may pass a boolean value to control if the color should be copyable or not:

 use Filament\Infolists\Components\ColorEntry;
 
 ColorEntry::make('color')
 ->copyable(FeatureFlag::active())

As well as allowing static values, the `copyable()`, `copyMessage()`, and `copyMessageDuration()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/infolists/docs/05-color-entry.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)