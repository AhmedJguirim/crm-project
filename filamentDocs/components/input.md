# Source: https://filamentphp.com/docs/4.x/components/input

Components 

# Input Blade component 

## [#](#introduction)Introduction

The input component is a wrapper around the native `<input>` element. It provides a simple interface for entering a single line of text.

 <x-filament::input.wrapper>
 <x-filament::input
 type="text"
 wire:model="name"
 />
 </x-filament::input.wrapper>

To use the input component, you must wrap it in an “input wrapper” component, which provides a border and other elements such as a prefix or suffix. You can learn more about customizing the input wrapper component [here](input-wrapper).

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-input.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)