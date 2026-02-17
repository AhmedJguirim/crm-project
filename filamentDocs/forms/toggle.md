# Source: https://filamentphp.com/docs/4.x/forms/toggle

Forms 

# Toggle 

## [#](#introduction)Introduction

The toggle component, similar to a [checkbox](checkbox), allows you to interact a boolean value.

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('is_admin')

![Toggle](/docs/4.x/images/light/forms/fields/toggle/simple.jpg) ![Toggle](/docs/4.x/images/dark/forms/fields/toggle/simple.jpg)

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

## [#](#adding-icons-to-the-toggle-button)Adding icons to the toggle button

Toggles may also use an [icon](../styling/icons) to represent the “on” and “off” state of the button. To add an icon to the “on” state, use the `onIcon()` method. To add an icon to the “off” state, use the `offIcon()` method:

 use Filament\Forms\Components\Toggle;
 use Filament\Support\Icons\Heroicon;
 
 Toggle::make('is_admin')
 ->onIcon(Heroicon::Bolt)
 ->offIcon(Heroicon::User)

As well as allowing static values, the `onIcon()` and `offIcon()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Toggle icons](/docs/4.x/images/light/forms/fields/toggle/icons.jpg) ![Toggle icons](/docs/4.x/images/dark/forms/fields/toggle/icons.jpg)

## [#](#customizing-the-color-of-the-toggle-button)Customizing the color of the toggle button

You may also customize the [color](../styling/colors) representing the “on” or “off” state of the toggle. To add a color to the “on” state, use the `onColor()` method. To add a color to the “off” state, use the `offColor()` method:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('is_admin')
 ->onColor('success')
 ->offColor('danger')

As well as allowing static values, the `onColor()` and `offColor()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Toggle off color](/docs/4.x/images/light/forms/fields/toggle/off-color.jpg) ![Toggle off color](/docs/4.x/images/dark/forms/fields/toggle/off-color.jpg)

![Toggle on color](/docs/4.x/images/light/forms/fields/toggle/on-color.jpg) ![Toggle on color](/docs/4.x/images/dark/forms/fields/toggle/on-color.jpg)

## [#](#positioning-the-label-above)Positioning the label above

Toggle fields have two layout modes, inline and stacked. By default, they are inline.

When the toggle is inline, its label is adjacent to it:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('is_admin')
 ->inline()

![Toggle with its label inline](/docs/4.x/images/light/forms/fields/toggle/inline.jpg) ![Toggle with its label inline](/docs/4.x/images/dark/forms/fields/toggle/inline.jpg)

When the toggle is stacked, its label is above it:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('is_admin')
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
 
![Toggle with its label above](/docs/4.x/images/light/forms/fields/toggle/not-inline.jpg) ![Toggle with its label above](/docs/4.x/images/dark/forms/fields/toggle/not-inline.jpg)

## [#](#toggle-validation)Toggle validation

As well as all rules listed on the [validation](validation) page, there are additional rules that are specific to toggles.

### [#](#accepted-validation)Accepted validation

You may ensure that the toggle is “on” using the `accepted()` method:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('terms_of_service')
 ->accepted()

Optionally, you may pass a boolean value to control if the validation rule should be applied or not:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('terms_of_service')
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

You may ensure that the toggle is “off” using the `declined()` method:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('is_under_18')
 ->declined()

Optionally, you may pass a boolean value to control if the validation rule should be applied or not:

 use Filament\Forms\Components\Toggle;
 
 Toggle::make('is_under_18')
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
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/05-toggle.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)