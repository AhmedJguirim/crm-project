# Source: https://filamentphp.com/docs/4.x/components/infolist

Components 

# Rendering an infolist in a Blade view 

NOTE

Before proceeding, make sure `filament/infolists` is installed in your project. You can check by running:

 composer show filament/infolists

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

## [#](#adding-the-infolist)Adding the infolist

Next, add a method to the Livewire component which accepts an `$infolist` object, modifies it, and returns it:

 use Filament\Schemas\Schema;
 
 public function productInfolist(Schema $schema): Schema
 {
 return $schema
 ->record($this->product)
 ->components([
 // ...
 ]);
 }

Finally, render the infolist in the Livewire component’s view:

 {{ $this->productInfolist }}

NOTE

`filament/infolists` also includes the following packages:

 * `filament/actions`
 * `filament/schemas`
 * `filament/support`

These packages allow you to use their components within Livewire components. For example, if your infolist uses [Actions](../actions), remember to implement the `HasActions` interface and use the `InteractsWithActions` trait on your Livewire component class.

If you are using any other [Filament components](overview#package-components) in your infolist, make sure to install and integrate the corresponding package as well.

## [#](#passing-data-to-the-infolist)Passing data to the infolist

You can pass data to the infolist in two ways:

Either pass an Eloquent model instance to the `record()` method of the infolist, to automatically map all the model attributes and relationships to the entries in the infolist’s schema:

 use Filament\Infolists\Components\TextEntry;
 use Filament\Schemas\Schema;
 
 public function productInfolist(Schema $schema): Schema
 {
 return $schema
 ->record($this->product)
 ->components([
 TextEntry::make('name'),
 TextEntry::make('category.name'),
 // ...
 ]);
 }

Alternatively, you can pass an array of data to the `state()` method of the infolist, to manually map the data to the entries in the infolist’s schema:

 use Filament\Infolists\Components\TextEntry;
 use Filament\Schemas\Schema;
 
 public function productInfolist(Schema $schema): Schema
 {
 return $schema
 ->constantState([
 'name' => 'MacBook Pro',
 'category' => [
 'name' => 'Laptops',
 ],
 // ...
 ])
 ->components([
 TextEntry::make('name'),
 TextEntry::make('category.name'),
 // ...
 ]);
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/12-components/02-infolist.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)