# Source: https://filamentphp.com/docs/4.x/infolists/key-value-entry

Infolists 

# Key-value entry 

## [#](#introduction)Introduction

The key-value entry allows you to render key-value pairs of data, from a one-dimensional JSON object / PHP array.

 use Filament\Infolists\Components\KeyValueEntry;
 
 KeyValueEntry::make('meta')

For example, the state of this entry might be represented as:

 [
 'description' => 'Filament is a collection of Laravel packages',
 'og:type' => 'website',
 'og:site_name' => 'Filament',
 ]

![Key-value entry](/docs/4.x/images/light/infolists/entries/key-value/simple.jpg) ![Key-value entry](/docs/4.x/images/dark/infolists/entries/key-value/simple.jpg)

If you’re saving the data in Eloquent, you should be sure to add an `array` [cast](https://laravel.com/docs/eloquent-mutators#array-and-json-casting) to the model property:

 use Illuminate\Database\Eloquent\Model;
 
 class Post extends Model
 {
 /**
 * @return array<string, string>
 */
 protected function casts(): array
 { 
 return [
 'meta' => 'array',
 ];
 }
 
 // ...
 }

## [#](#customizing-the-key-columns-label)Customizing the key column’s label

You may customize the label for the key column using the `keyLabel()` method:

 use Filament\Infolists\Components\KeyValueEntry;
 
 KeyValueEntry::make('meta')
 ->keyLabel('Property name')

As well as allowing a static value, the `keyLabel()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#customizing-the-value-columns-label)Customizing the value column’s label

You may customize the label for the value column using the `valueLabel()` method:

 use Filament\Infolists\Components\KeyValueEntry;
 
 KeyValueEntry::make('meta')
 ->valueLabel('Property value')

As well as allowing a static value, the `valueLabel()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/infolists/docs/07-key-value-entry.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)