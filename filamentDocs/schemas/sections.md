# Source: https://filamentphp.com/docs/4.x/schemas/sections

Schemas 

# Sections 

## [#](#introduction)Introduction

You may want to separate your fields into sections, each with a heading and description. To do this, you can use a section component:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->schema([
 // ...
 ])

As well as allowing static values, the `make()` and `description()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Section](/docs/4.x/images/light/schemas/layout/section/simple.jpg) ![Section](/docs/4.x/images/dark/schemas/layout/section/simple.jpg)

You can also use a section without a header, which just wraps the components in a simple card:

 use Filament\Schemas\Components\Section;
 
 Section::make()
 ->schema([
 // ...
 ])

![Section without header](/docs/4.x/images/light/schemas/layout/section/without-header.jpg) ![Section without header](/docs/4.x/images/dark/schemas/layout/section/without-header.jpg)

## [#](#adding-an-icon-to-the-sections-header)Adding an icon to the section’s header

You may add an [icon](../styling/icons) to the section’s header using the `icon()` method:

 use Filament\Schemas\Components\Section;
 use Filament\Support\Icons\Heroicon;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->icon(Heroicon::ShoppingBag)
 ->schema([
 // ...
 ])

As well as allowing a static value, the `icon()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Section with icon](/docs/4.x/images/light/schemas/layout/section/icons.jpg) ![Section with icon](/docs/4.x/images/dark/schemas/layout/section/icons.jpg)

## [#](#positioning-the-heading-and-description-aside)Positioning the heading and description aside

You may use the `aside()` to align heading & description on the left, and the components inside a card on the right:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->aside()
 ->schema([
 // ...
 ])

![Section with heading and description aside](/docs/4.x/images/light/schemas/layout/section/aside.jpg) ![Section with heading and description aside](/docs/4.x/images/dark/schemas/layout/section/aside.jpg)

Optionally, you may pass a boolean value to control if the section should be aside or not:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->aside(FeatureFlag::active())
 ->schema([
 // ...
 ])

As well as allowing a static value, the `aside()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#collapsing-sections)Collapsing sections

Sections may be `collapsible()` to optionally hide long content:

 use Filament\Schemas\Components\Section;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->schema([
 // ...
 ])
 ->collapsible()

Your sections may be `collapsed()` by default:

 use Filament\Schemas\Components\Section;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->schema([
 // ...
 ])
 ->collapsed()

![Collapsed section](/docs/4.x/images/light/schemas/layout/section/collapsed.jpg) ![Collapsed section](/docs/4.x/images/dark/schemas/layout/section/collapsed.jpg)

Optionally, the `collapsible()` and `collapsed()` methods accept a boolean value to control if the section should be collapsible and collapsed or not:

 use Filament\Schemas\Components\Section;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->schema([
 // ...
 ])
 ->collapsible(FeatureFlag::active())
 ->collapsed(FeatureFlag::active())

As well as allowing static values, the `collapsible()` and `collapsed()` methods also accept functions to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
### [#](#persisting-collapsed-sections-in-the-users-session)Persisting collapsed sections in the user’s session

You can persist whether a section is collapsed in local storage using the `persistCollapsed()` method, so it will remain collapsed when the user refreshes the page:

 use Filament\Schemas\Components\Section;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->schema([
 // ...
 ])
 ->collapsible()
 ->persistCollapsed()

To persist the collapse state, the local storage needs a unique ID to store the state. This ID is generated based on the heading of the section. If your section does not have a heading, or if you have multiple sections with the same heading that you do not want to collapse together, you can manually specify the `id()` of that section to prevent an ID conflict:

 use Filament\Schemas\Components\Section;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->schema([
 // ...
 ])
 ->collapsible()
 ->persistCollapsed()
 ->id('order-cart')

Optionally, the `persistCollapsed()` method accepts a boolean value to control if the section should persist its collapsed state or not:

 use Filament\Schemas\Components\Section;
 
 Section::make('Cart')
 ->description('The items you have selected for purchase')
 ->schema([
 // ...
 ])
 ->collapsible()
 ->persistCollapsed(FeatureFlag::active())

As well as allowing static values, the `persistCollapsed()` and `id()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#compact-section-styling)Compact section styling

When nesting sections, you can use a more compact styling:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->schema([
 // ...
 ])
 ->compact()

![Compact section](/docs/4.x/images/light/schemas/layout/section/compact.jpg) ![Compact section](/docs/4.x/images/dark/schemas/layout/section/compact.jpg)

Optionally, the `compact()` method accepts a boolean value to control if the section should be compact or not:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->schema([
 // ...
 ])
 ->compact(FeatureFlag::active())

As well as allowing a static value, the `compact()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#secondary-section-styling)Secondary section styling

By default, sections have a contrasting background color, which makes them stand out against a gray background. Secondary styling gives the section a less contrasting background, so it is usually slightly darker. This is a better styling when the background color behind the section is the same color as the default section background color, for example when a section is nested inside another section. Secondary sections can be created using the `secondary()` method:

 use Filament\Schemas\Components\Section;
 
 Section::make('Notes')
 ->schema([
 // ...
 ])
 ->secondary()
 ->compact()

![Secondary section](/docs/4.x/images/light/schemas/layout/section/secondary.jpg) ![Secondary section](/docs/4.x/images/dark/schemas/layout/section/secondary.jpg)

Optionally, the `secondary()` method accepts a boolean value to control if the section should be secondary or not:

 use Filament\Schemas\Components\Section;
 
 Section::make('Notes')
 ->schema([
 // ...
 ])
 ->secondary(FeatureFlag::active())

## [#](#inserting-actions-and-other-components-in-the-header-of-a-section)Inserting actions and other components in the header of a section

You may insert [actions](../actions) and any other schema component (usually [prime components](primes)) into the header of a section by passing an array of components to the `afterHeader()` method:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->afterHeader([
 Action::make('test'),
 ])
 ->schema([
 // ...
 ])

As well as allowing a static value, the `afterHeader()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Section with actions in the header](/docs/4.x/images/light/schemas/layout/section/header/actions.jpg) ![Section with actions in the header](/docs/4.x/images/dark/schemas/layout/section/header/actions.jpg)

## [#](#inserting-actions-and-other-components-in-the-footer-of-a-section)Inserting actions and other components in the footer of a section

You may insert [actions](../actions) and any other schema component (usually [prime components](primes)) into the footer of a section by passing an array of components to the `footer()` method:

 use Filament\Schemas\Components\Section;
 
 Section::make('Rate limiting')
 ->description('Prevent abuse by limiting the number of requests per period')
 ->schema([
 // ...
 ])
 ->footer([
 Action::make('test'),
 ])

As well as allowing a static value, the `footer()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Section with actions in the footer](/docs/4.x/images/light/schemas/layout/section/footer/actions.jpg) ![Section with actions in the footer](/docs/4.x/images/dark/schemas/layout/section/footer/actions.jpg)

## [#](#using-grid-columns-within-a-section)Using grid columns within a section

You may use the `columns()` method to easily create a [grid](layouts#grid-system) within the section:

 use Filament\Schemas\Components\Section;
 
 Section::make('Heading')
 ->schema([
 // ...
 ])
 ->columns(2)

As well as allowing a static value, the `columns()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/schemas/docs/03-sections.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)