# Source: https://filamentphp.com/docs/4.x/components/checkbox

Components 

# Checkbox Blade component 

## [#](#introduction)Introduction

You can use the checkbox component to render a checkbox input that can be used to toggle a boolean value:

 <label>
 <x-filament::input.checkbox wire:model="isAdmin" />
 
 <span>
 Is Admin
 </span>
 </label>

## [#](#triggering-the-error-state-of-the-checkbox)Triggering the error state of the checkbox

The checkbox has special styling that you can use if it is invalid. To trigger this styling, you can use either Blade or Alpine.js.

To trigger the error state using Blade, you can pass the `valid` attribute to the component, which contains either true or false based on if the checkbox is valid or not:

 <x-filament::input.checkbox
 wire:model="isAdmin"
 :valid="! $errors->has('isAdmin')"
 />

Alternatively, you can use an Alpine.js expression to trigger the error state, based on if it evaluates to `true` or `false`:

 <div x-data="{ errors: ['isAdmin'] }">
 <x-filament::input.checkbox
 x-model="isAdmin"
 alpine-valid="! errors.includes('isAdmin')"
 />
 </div>

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/03-checkbox.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)