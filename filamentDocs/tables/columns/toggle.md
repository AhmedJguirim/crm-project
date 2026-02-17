# Source: https://filamentphp.com/docs/4.x/tables/columns/toggle

Tables \- Columns 

# Toggle column 

## [#](#introduction)Introduction

The toggle column allows you to render a toggle button inside the table, which can be used to update that database record without needing to open a new page or a modal:

 use Filament\Tables\Columns\ToggleColumn;
 
 ToggleColumn::make('is_admin')

![Toggle column](/docs/4.x/images/light/tables/columns/toggle/simple.jpg) ![Toggle column](/docs/4.x/images/dark/tables/columns/toggle/simple.jpg)

## [#](#lifecycle-hooks)Lifecycle hooks

Hooks may be used to execute code at various points within the toggle’s lifecycle:

 ToggleColumn::make()
 ->beforeStateUpdated(function ($record, $state) {
 // Runs before the state is saved to the database.
 })
 ->afterStateUpdated(function ($record, $state) {
 // Runs after the state is saved to the database.
 })

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/07-toggle.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)