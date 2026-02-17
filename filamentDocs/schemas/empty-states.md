# Source: https://filamentphp.com/docs/4.x/schemas/empty-states

Schemas 

# Empty states 

## [#](#introduction)Introduction

You can display an empty state in your schema to communicate that there is no content to show yet, and to guide the user towards the next action. An empty state requires a heading, but can also have a `description()`, [`icon()`](#adding-an-icon-to-the-empty-state) and [`footer()`](#inserting-actions-and-other-components-in-the-footer-of-an-empty-state):

 use Filament\Actions\Action;
 use Filament\Schemas\Components\EmptyState;
 use Filament\Support\Icons\Heroicon;
 
 EmptyState::make('No users yet')
 ->description('Get started by creating a new user.')
 ->icon(Heroicon::OutlinedUser)
 ->footer([
 Action::make('createUser')
 ->icon(Heroicon::Plus),
 ])

As well as allowing static values, the `make()` and `description()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Empty state](/docs/4.x/images/light/schemas/layout/empty-state/simple.jpg) ![Empty state](/docs/4.x/images/dark/schemas/layout/empty-state/simple.jpg)

## [#](#adding-an-icon-to-the-empty-state)Adding an icon to the empty state

You may add an [icon](../styling/icons) to the empty state using the `icon()` method:

 use Filament\Schemas\Components\EmptyState;
 use Filament\Support\Icons\Heroicon;
 
 EmptyState::make('No users yet')
 ->description('Get started by creating a new user.')
 ->icon(Heroicon::OutlinedUser)

As well as allowing a static value, the `icon()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#inserting-actions-and-other-components-in-the-footer-of-an-empty-state)Inserting actions and other components in the footer of an empty state

You may insert [actions](../actions) and any other schema component (usually [prime components](primes)) into the footer of an empty state by passing an array of components to the `footer()` method:

 use Filament\Actions\Action;
 use Filament\Schemas\Components\EmptyState;
 
 EmptyState::make('No users yet')
 ->description('Get started by creating a new user.')
 ->footer([
 Action::make('createUser')
 ->icon(Heroicon::Plus),
 ])

As well as allowing a static value, the `footer()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#removing-the-empty-state-container)Removing the empty state container

By default, empty states have a background color, shadow and border. You may remove these styles and just render the content of the empty state without the container using `contained(false)`:

 use Filament\Schemas\Components\EmptyState;
 
 EmptyState::make('No users yet')
 ->description('Get started by creating a new user.')
 ->contained(false)

As well as allowing a static value, the `contained()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/schemas/docs/06-empty-states.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)