# Source: https://filamentphp.com/docs/4.x/components/select

Components 

# Select Blade component 

## [#](#introduction)Introduction

The select component is a wrapper around the native `<select>` element. It provides a simple interface for selecting a single value from a list of options:

 <x-filament::input.wrapper>
 <x-filament::input.select wire:model="status">
 <option value="draft">Draft</option>
 <option value="reviewing">Reviewing</option>
 <option value="published">Published</option>
 </x-filament::input.select>
 </x-filament::input.wrapper>

To use the select component, you must wrap it in an “input wrapper” component, which provides a border and other elements such as a prefix or suffix. You can learn more about customizing the input wrapper component [here](input-wrapper).

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-select.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)