# Source: https://filamentphp.com/docs/4.x/forms/hidden

Forms 

# Hidden 

## [#](#introduction)Introduction

The hidden component allows you to create a hidden field in your form that holds a value.

 use Filament\Forms\Components\Hidden;
 
 Hidden::make('token')

Please be aware that the value of this field is still editable by the user if they decide to use the browser’s developer tools. You should not use this component to store sensitive or read-only information.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/21-hidden.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)