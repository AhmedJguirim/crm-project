# Source: https://filamentphp.com/docs/4.x/components/badge

Components 

# Badge Blade component 

## [#](#introduction)Introduction

The badge component is used to render a small box with some text inside:

 <x-filament::badge>
 New
 </x-filament::badge>

## [#](#setting-the-size-of-a-badge)Setting the size of a badge

By default, the size of a badge is “medium”. You can make it “extra small” or “small” by using the `size` attribute:

 <x-filament::badge size="xs">
 New
 </x-filament::badge>
 
 <x-filament::badge size="sm">
 New
 </x-filament::badge>

## [#](#changing-the-color-of-the-badge)Changing the color of the badge

By default, the color of a badge is “primary”. You can change it to be `danger`, `gray`, `info`, `success` or `warning` by using the `color` attribute:

 <x-filament::badge color="danger">
 New
 </x-filament::badge>
 
 <x-filament::badge color="gray">
 New
 </x-filament::badge>
 
 <x-filament::badge color="info">
 New
 </x-filament::badge>
 
 <x-filament::badge color="success">
 New
 </x-filament::badge>
 
 <x-filament::badge color="warning">
 New
 </x-filament::badge>

## [#](#adding-an-icon-to-a-badge)Adding an icon to a badge

You can add an [icon](../styling/icons) to a badge by using the `icon` attribute:

 <x-filament::badge icon="heroicon-m-sparkles">
 New
 </x-filament::badge>

You can also change the icon’s position to be after the text instead of before it, using the `icon-position` attribute:

 <x-filament::badge
 icon="heroicon-m-sparkles"
 icon-position="after"
 >
 New
 </x-filament::badge>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-badge.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)