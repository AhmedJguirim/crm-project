# Source: https://filamentphp.com/docs/4.x/forms/checkbox

Forms 

# Checkbox 

## [#](#introduction)Introduction

The checkbox component, similar to a [toggle](toggle), allows you to interact a boolean value.

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('is_admin')

![Checkbox](/docs/4.x/images/light/forms/fields/checkbox/simple.jpg) ![Checkbox](/docs/4.x/images/dark/forms/fields/checkbox/simple.jpg)

If you’re saving the boolean value using Eloquent, you should be sure to add a `boolean` [cast](https://laravel.com/docs/eloquent-mutators#attribute-casting) to the model property:

 use Illuminate\Database\Eloquent\Model;
 
 class User extends Model
 {
 /**
 * @return array<string, string>
 */
 protected function casts(): array
 {
 return [
 'is_admin' => 'boolean',
 ];
 }
 
 // ...
 }

## [#](#positioning-the-label-above)Positioning the label above

Checkbox fields have two layout modes, inline and stacked. By default, they are inline.

When the checkbox is inline, its label is adjacent to it:

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('is_admin')
 ->inline()

![Checkbox with its label inline](/docs/4.x/images/light/forms/fields/checkbox/inline.jpg) ![Checkbox with its label inline](/docs/4.x/images/dark/forms/fields/checkbox/inline.jpg)

When the checkbox is stacked, its label is above it:

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('is_admin')
 ->inline(false)

As well as allowing a static value, the `inline()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Checkbox with its label above](/docs/4.x/images/light/forms/fields/checkbox/not-inline.jpg) ![Checkbox with its label above](/docs/4.x/images/dark/forms/fields/checkbox/not-inline.jpg)

## [#](#checkbox-validation)Checkbox validation

As well as all rules listed on the [validation](validation) page, there are additional rules that are specific to checkboxes.

### [#](#accepted-validation)Accepted validation

You may ensure that the checkbox is checked using the `accepted()` method:

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('terms_of_service')
 ->accepted()

Optionally, you may pass a boolean value to control if the validation rule should be applied or not:

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('terms_of_service')
 ->accepted(FeatureFlag::active())

As well as allowing a static value, the `accepted()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#declined-validation)Declined validation

You may ensure that the checkbox is not checked using the `declined()` method:

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('is_under_18')
 ->declined()

Optionally, you may pass a boolean value to control if the validation rule should be applied or not:

 use Filament\Forms\Components\Checkbox;
 
 Checkbox::make('is_under_18')
 ->declined(FeatureFlag::active())

As well as allowing a static value, the `declined()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/04-checkbox.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)