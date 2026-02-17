# Source: https://filamentphp.com/docs/4.x/components/schema

Components 

# Rendering a schema in a Blade view 

NOTE

Before proceeding, make sure `filament/schemas` is installed in your project. You can check by running:

 composer show filament/schemas

If it’s not installed, consult the [installation guide](../introduction/installation#installing-the-individual-components) and configure the **individual components** according to the instructions.

## [#](#setting-up-the-livewire-component)Setting up the Livewire component

First, generate a new Livewire component:

 php artisan make:livewire ViewProduct

Then, render your Livewire component on the page:

 @livewire('view-product')

Alternatively, you can use a full-page Livewire component:

 use App\Livewire\ViewProduct;
 use Illuminate\Support\Facades\Route;
 
 Route::get('products/{product}', ViewProduct::class);

You must use the `InteractsWithSchemas` trait, and implement the `HasSchemas` interface on your Livewire component class:

 use Filament\Schemas\Concerns\InteractsWithSchemas;
 use Filament\Schemas\Contracts\HasSchemas;
 use Livewire\Component;
 
 class ViewProduct extends Component implements HasSchemas
 {
 use InteractsWithSchemas;
 
 // ...
 }

## [#](#adding-the-schema)Adding the schema

Next, add a method to the Livewire component which accepts a `$schema` object, modifies it, and returns it:

 use Filament\Schemas\Schema;
 
 public function productSchema(Schema $schema): Schema
 {
 return $schema
 ->components([
 // ...
 ]);
 }

Finally, render the schema in the Livewire component’s view:

 {{ $this->productSchema }}

NOTE

`filament/schemas` also includes the following packages:

 * `filament/actions`
 * `filament/support`

These packages allow you to use their components within Livewire components. For example, if your schema uses [Actions](../actions), remember to implement the `HasActions` interface and use the `InteractsWithActions` trait on your Livewire component class.

If you are using any other [Filament components](overview#package-components) in your schema, make sure to install and integrate the corresponding package as well.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/02-schema.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)