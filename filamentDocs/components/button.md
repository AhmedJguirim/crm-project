# Source: https://filamentphp.com/docs/4.x/components/button

Components 

# Button Blade component 

## [#](#introduction)Introduction

The button component is used to render a clickable button that can perform an action:

 <x-filament::button wire:click="openNewUserModal">
 New user
 </x-filament::button>

## [#](#using-a-button-as-an-anchor-link)Using a button as an anchor link

By default, a button’s underlying HTML tag is `<button>`. You can change it to be an `<a>` tag by using the `tag` attribute:

 <x-filament::button
 href="https://filamentphp.com"
 tag="a"
 >
 Filament
 </x-filament::button>

## [#](#setting-the-size-of-a-button)Setting the size of a button

By default, the size of a button is “medium”. You can make it “extra small”, “small”, “large” or “extra large” by using the `size` attribute:

 <x-filament::button size="xs">
 New user
 </x-filament::button>
 
 <x-filament::button size="sm">
 New user
 </x-filament::button>
 
 <x-filament::button size="lg">
 New user
 </x-filament::button>
 
 <x-filament::button size="xl">
 New user
 </x-filament::button>

## [#](#changing-the-color-of-a-button)Changing the color of a button

By default, the color of a button is “primary”. You can change it to be `danger`, `gray`, `info`, `success` or `warning` by using the `color` attribute:

 <x-filament::button color="danger">
 New user
 </x-filament::button>
 
 <x-filament::button color="gray">
 New user
 </x-filament::button>
 
 <x-filament::button color="info">
 New user
 </x-filament::button>
 
 <x-filament::button color="success">
 New user
 </x-filament::button>
 
 <x-filament::button color="warning">
 New user
 </x-filament::button>

## [#](#adding-an-icon-to-a-button)Adding an icon to a button

You can add an [icon](../styling/icons) to a button by using the `icon` attribute:

 <x-filament::button icon="heroicon-m-sparkles">
 New user
 </x-filament::button>

You can also change the icon’s position to be after the text instead of before it, using the `icon-position` attribute:

 <x-filament::button
 icon="heroicon-m-sparkles"
 icon-position="after"
 >
 New user
 </x-filament::button>

## [#](#making-a-button-outlined)Making a button outlined

You can make a button use an “outlined” design using the `outlined` attribute:

 <x-filament::button outlined>
 New user
 </x-filament::button>

## [#](#adding-a-tooltip-to-a-button)Adding a tooltip to a button

You can add a tooltip to a button by using the `tooltip` attribute:

 <x-filament::button tooltip="Register a user">
 New user
 </x-filament::button>

## [#](#adding-a-badge-to-a-button)Adding a badge to a button

You can render a [badge](badge) on top of a button by using the `badge` slot:

 <x-filament::button>
 Mark notifications as read
 
 <x-slot name="badge">
 3
 </x-slot>
 </x-filament::button>

You can [change the color](badge#changing-the-color-of-the-badge) of the badge using the `badge-color` attribute:

 <x-filament::button badge-color="danger">
 Mark notifications as read
 
 <x-slot name="badge">
 3
 </x-slot>
 </x-filament::button>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-button.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)