# Source: https://filamentphp.com/docs/4.x/components/widget

Components 

# Rendering a widget in a Blade view 

NOTE

Before proceeding, make sure `filament/widgets` is installed in your project. You can check by running:

 composer show filament/widgets

If it’s not installed, consult the [installation guide](../introduction/installation#installing-the-individual-components) and configure the **individual components** according to the instructions.

## [#](#creating-a-widget)Creating a widget

Use the `make:filament-widget` command to generate a new widget. For details on customization and usage, see the [widgets section](../widgets).

## [#](#adding-the-widget)Adding the widget

Since widgets are Livewire components, you can easily render a widget in any Blade view using the `@livewire` directive:

 <div>
 @livewire(\App\Livewire\Dashboard\PostsChart::class)
 </div>

NOTE

If you’re using a [table widget](../widgets/overview#table-widgets), make sure to install `filament/tables` as well. 
Refer to the [installation guide](../introduction/installation#installing-the-individual-components) and follow the steps to configure the **individual components** properly.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/02-widget.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)