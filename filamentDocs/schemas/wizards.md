# Source: https://filamentphp.com/docs/4.x/schemas/wizards

Schemas 

# Wizards 

## [#](#introduction)Introduction

Similar to [tabs](tabs), you may want to use a multistep wizard to reduce the number of components that are visible at once. These are especially useful if your form has a definite chronological order, in which you want each step to be validated as the user progresses.

 use Filament\Schemas\Components\Wizard;
 use Filament\Schemas\Components\Wizard\Step;
 
 Wizard::make([
 Step::make('Order')
 ->schema([
 // ...
 ]),
 Step::make('Delivery')
 ->schema([
 // ...
 ]),
 Step::make('Billing')
 ->schema([
 // ...
 ]),
 ])

![Wizard](/docs/4.x/images/light/schemas/layout/wizard/simple.jpg) ![Wizard](/docs/4.x/images/dark/schemas/layout/wizard/simple.jpg)

TIP

We have different setup instructions if you’re looking to add a wizard to the creation process inside a [panel resource](../resources/creating-records#using-a-wizard) or an [action modal](../actions/modals#rendering-a-wizard-in-a-modal). Following that documentation will ensure that the ability to submit the form is only available on the last step of the wizard.

## [#](#rendering-a-submit-button-on-the-last-step)Rendering a submit button on the last step

You may use the `submitAction()` method to render submit button HTML or a view at the end of the wizard, on the last step. This provides a clearer UX than displaying a submit button below the wizard at all times:

 use Filament\Schemas\Components\Wizard;
 use Illuminate\Support\HtmlString;
 
 Wizard::make([
 // ...
 ])->submitAction(view('order-form.submit-button'))
 
 Wizard::make([
 // ...
 ])->submitAction(new HtmlString('<button type="submit">Submit</button>'))

Alternatively, you can use the built-in Filament button Blade component:

 use Filament\Schemas\Components\Wizard;
 use Illuminate\Support\Facades\Blade;
 use Illuminate\Support\HtmlString;
 
 Wizard::make([
 // ...
 ])->submitAction(new HtmlString(Blade::render(<<<BLADE
 <x-filament::button
 type="submit"
 size="sm"
 >
 Submit
 </x-filament::button>
 BLADE)))

You could extract this component to a separate Blade view if you prefer.

## [#](#setting-a-step-icon)Setting a step icon

Steps may have an [icon](../styling/icons), which you can set using the `icon()` method:

 use Filament\Schemas\Components\Wizard\Step;
 use Filament\Support\Icons\Heroicon;
 
 Step::make('Order')
 ->icon(Heroicon::ShoppingBag)
 ->schema([
 // ...
 ]),

As well as allowing a static value, the `icon()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Wizard with step icons](/docs/4.x/images/light/schemas/layout/wizard/icons.jpg) ![Wizard with step icons](/docs/4.x/images/dark/schemas/layout/wizard/icons.jpg)

## [#](#customizing-the-icon-for-completed-steps)Customizing the icon for completed steps

You may customize the [icon](#setting-up-step-icons) of a completed step using the `completedIcon()` method:

 use Filament\Schemas\Components\Wizard\Step;
 use Filament\Support\Icons\Heroicon;
 
 Step::make('Order')
 ->completedIcon(Heroicon::HandThumbUp)
 ->schema([
 // ...
 ]),

As well as allowing a static value, the `completedIcon()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Wizard with completed step icons](/docs/4.x/images/light/schemas/layout/wizard/completed-icons.jpg) ![Wizard with completed step icons](/docs/4.x/images/dark/schemas/layout/wizard/completed-icons.jpg)

## [#](#adding-descriptions-to-steps)Adding descriptions to steps

You may add a short description after the title of each step using the `description()` method:

 use Filament\Schemas\Components\Wizard\Step;
 
 Step::make('Order')
 ->description('Review your basket')
 ->schema([
 // ...
 ]),

As well as allowing a static value, the `description()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Wizard with step descriptions](/docs/4.x/images/light/schemas/layout/wizard/descriptions.jpg) ![Wizard with step descriptions](/docs/4.x/images/dark/schemas/layout/wizard/descriptions.jpg)

## [#](#setting-the-default-active-step)Setting the default active step

You may use the `startOnStep()` method to load a specific step in the wizard:

 use Filament\Schemas\Components\Wizard;
 
 Wizard::make([
 // ...
 ])->startOnStep(2)

As well as allowing a static value, the `startOnStep()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#allowing-steps-to-be-skipped)Allowing steps to be skipped

If you’d like to allow free navigation, so all steps are skippable, use the `skippable()` method:

 use Filament\Schemas\Components\Wizard;
 
 Wizard::make([
 // ...
 ])->skippable()

Optionally, the `skippable()` method accepts a boolean value to control if the steps are skippable or not:

 use Filament\Schemas\Components\Wizard;
 
 Wizard::make([
 // ...
 ])->skippable(FeatureFlag::active())

As well as allowing a static value, the `skippable()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#persisting-the-current-step-in-the-urls-query-string)Persisting the current step in the URL’s query string

By default, the current step is not persisted in the URL’s query string. You can change this behavior using the `persistStepInQueryString()` method:

 use Filament\Schemas\Components\Wizard;
 
 Wizard::make([
 // ...
 ])->persistStepInQueryString()

When enabled, the current step is persisted in the URL’s query string using the `step` key. You can change this key by passing it to the `persistStepInQueryString()` method:

 use Filament\Schemas\Components\Wizard;
 
 Wizard::make([
 // ...
 ])->persistStepInQueryString('wizard-step')

As well as allowing a static value, the `persistStepInQueryString()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#step-lifecycle-hooks)Step lifecycle hooks

You may use the `afterValidation()` and `beforeValidation()` methods to run code before and after validation occurs on the step:

 use Filament\Schemas\Components\Wizard\Step;
 
 Step::make('Order')
 ->afterValidation(function () {
 // ...
 })
 ->beforeValidation(function () {
 // ...
 })
 ->schema([
 // ...
 ]),

You can inject various utilities into the `afterValidation()` and `beforeValidation()` functions as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
### [#](#preventing-the-next-step-from-being-loaded)Preventing the next step from being loaded

Inside `afterValidation()` or `beforeValidation()`, you may throw `Filament\Support\Exceptions\Halt`, which will prevent the wizard from loading the next step:

 use Filament\Schemas\Components\Wizard\Step;
 use Filament\Support\Exceptions\Halt;
 
 Step::make('Order')
 ->afterValidation(function () {
 // ...
 
 if (true) {
 throw new Halt();
 }
 })
 ->schema([
 // ...
 ]),

## [#](#using-grid-columns-within-a-step)Using grid columns within a step

You may use the `columns()` method to customize the [grid](layouts#grid-system) within the step:

 use Filament\Schemas\Components\Wizard;
 use Filament\Schemas\Components\Wizard\Step;
 
 Wizard::make([
 Step::make('Order')
 ->columns(2)
 ->schema([
 // ...
 ]),
 // ...
 ])

As well as allowing a static value, the `columns()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#customizing-the-wizard-action-objects)Customizing the wizard action objects

This component uses action objects for easy customization of buttons within it. You can customize these buttons by passing a function to an action registration method. The function has access to the `$action` object, which you can use to [customize it](../actions/overview). The following methods are available to customize the actions:

 * `nextAction()`
 * `previousAction()`

Here is an example of how you might customize an action:

 use Filament\Actions\Action;
 use Filament\Schemas\Components\Wizard;
 
 Wizard::make([
 // ...
 ])
 ->nextAction(
 fn (Action $action) => $action->label('Next step'),
 )

The action registration methods can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Action | `Filament\Actions\Action` | `$action` | The action object to customize. 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/schemas/docs/05-wizards.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)