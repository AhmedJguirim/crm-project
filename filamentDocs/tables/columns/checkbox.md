# Source: https://filamentphp.com/docs/4.x/tables/columns/checkbox

Tables \- Columns 

# Checkbox column 

## [#](#introduction)Introduction

The checkbox column allows you to render a checkbox inside the table, which can be used to update that database record without needing to open a new page or a modal:

 use Filament\Tables\Columns\CheckboxColumn;
 
 CheckboxColumn::make('is_admin')

![Checkbox column](/docs/4.x/images/light/tables/columns/checkbox/simple.jpg) ![Checkbox column](/docs/4.x/images/dark/tables/columns/checkbox/simple.jpg)

## [#](#lifecycle-hooks)Lifecycle hooks

Hooks may be used to execute code at various points within the checkbox’s lifecycle:

 CheckboxColumn::make()
 ->beforeStateUpdated(function ($record, $state) {
 // Runs before the state is saved to the database.
 })
 ->afterStateUpdated(function ($record, $state) {
 // Runs after the state is saved to the database.
 })

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/09-checkbox.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)