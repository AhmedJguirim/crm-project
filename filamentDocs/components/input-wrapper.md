# Source: https://filamentphp.com/docs/4.x/components/input-wrapper

Components 

# Input wrapper Blade component 

## [#](#introduction)Introduction

The input wrapper component should be used as a wrapper around the [input](input) or [select](select) components. It provides a border and other elements such as a prefix or suffix.

 <x-filament::input.wrapper>
 <x-filament::input
 type="text"
 wire:model="name"
 />
 </x-filament::input.wrapper>
 
 <x-filament::input.wrapper>
 <x-filament::input.select wire:model="status">
 <option value="draft">Draft</option>
 <option value="reviewing">Reviewing</option>
 <option value="published">Published</option>
 </x-filament::input.select>
 </x-filament::input.wrapper>

## [#](#triggering-the-error-state-of-the-input)Triggering the error state of the input

The component has special styling that you can use if it is invalid. To trigger this styling, you can use either Blade or Alpine.js.

To trigger the error state using Blade, you can pass the `valid` attribute to the component, which contains either true or false based on if the input is valid or not:

 <x-filament::input.wrapper :valid="! $errors->has('name')">
 <x-filament::input
 type="text"
 wire:model="name"
 />
 </x-filament::input.wrapper>

Alternatively, you can use an Alpine.js expression to trigger the error state, based on if it evaluates to `true` or `false`:

 <div x-data="{ errors: ['name'] }">
 <x-filament::input.wrapper alpine-valid="! errors.includes('name')">
 <x-filament::input
 type="text"
 wire:model="name"
 />
 </x-filament::input.wrapper>
 </div>

## [#](#disabling-the-input)Disabling the input

To disable the input, you must also pass the `disabled` attribute to the wrapper component:

 <x-filament::input.wrapper disabled>
 <x-filament::input
 type="text"
 wire:model="name"
 disabled
 />
 </x-filament::input.wrapper>

## [#](#adding-affix-text-aside-the-input)Adding affix text aside the input

You may place text before and after the input using the `prefix` and `suffix` slots:

 <x-filament::input.wrapper>
 <x-slot name="prefix">
 https://
 </x-slot>
 
 <x-filament::input
 type="text"
 wire:model="domain"
 />
 
 <x-slot name="suffix">
 .com
 </x-slot>
 </x-filament::input.wrapper>

### [#](#using-icons-as-affixes)Using icons as affixes

You may place an [icon](../styling/icons) before and after the input using the `prefix-icon` and `suffix-icon` attributes:

 <x-filament::input.wrapper suffix-icon="heroicon-m-globe-alt">
 <x-filament::input
 type="url"
 wire:model="domain"
 />
 </x-filament::input.wrapper>

#### [#](#setting-the-affix-icons-color)Setting the affix icon’s color

Affix icons are gray by default, but you may set a different color using the `prefix-icon-color` and `affix-icon-color` attributes:

 <x-filament::input.wrapper
 suffix-icon="heroicon-m-check-circle"
 suffix-icon-color="success"
 >
 <x-filament::input
 type="url"
 wire:model="domain"
 />
 </x-filament::input.wrapper>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-input-wrapper.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)