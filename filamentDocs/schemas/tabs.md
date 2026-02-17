# Source: https://filamentphp.com/docs/4.x/schemas/tabs

Schemas 

# Tabs 

## [#](#introduction)Introduction

Some schemas can be long and complex. You may want to use tabs to reduce the number of components that are visible at once:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 2')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 3')
 ->schema([
 // ...
 ]),
 ])

As well as allowing a static value, the `make()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Tabs](/docs/4.x/images/light/schemas/layout/tabs/simple.jpg) ![Tabs](/docs/4.x/images/dark/schemas/layout/tabs/simple.jpg)

## [#](#setting-the-default-active-tab)Setting the default active tab

The first tab will be open by default. You can change the default open tab using the `activeTab()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 2')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 3')
 ->schema([
 // ...
 ]),
 ])
 ->activeTab(2)

As well as allowing a static value, the `activeTab()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#setting-a-tab-icon)Setting a tab icon

Tabs may have an [icon](../styling/icons), which you can set using the `icon()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 use Filament\Support\Icons\Heroicon;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Notifications')
 ->icon(Heroicon::Bell)
 ->schema([
 // ...
 ]),
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
 
![Tabs with icons](/docs/4.x/images/light/schemas/layout/tabs/icons.jpg) ![Tabs with icons](/docs/4.x/images/dark/schemas/layout/tabs/icons.jpg)

### [#](#setting-the-tab-icon-position)Setting the tab icon position

The icon of the tab may be positioned before or after the label using the `iconPosition()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 use Filament\Support\Enums\IconPosition;
 use Filament\Support\Icons\Heroicon;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Notifications')
 ->icon(Heroicon::Bell)
 ->iconPosition(IconPosition::After)
 ->schema([
 // ...
 ]),
 // ...
 ])

As well as allowing a static value, the `iconPosition()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Tabs with icons after their labels](/docs/4.x/images/light/schemas/layout/tabs/icons-after.jpg) ![Tabs with icons after their labels](/docs/4.x/images/dark/schemas/layout/tabs/icons-after.jpg)

## [#](#setting-a-tab-badge)Setting a tab badge

Tabs may have a badge, which you can set using the `badge()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Notifications')
 ->badge(5)
 ->schema([
 // ...
 ]),
 // ...
 ])

As well as allowing a static value, the `badge()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Tabs with badges](/docs/4.x/images/light/schemas/layout/tabs/badges.jpg) ![Tabs with badges](/docs/4.x/images/dark/schemas/layout/tabs/badges.jpg)

If you’d like to change the [color](../styling/colors) for a badge, you can use the `badgeColor()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Notifications')
 ->badge(5)
 ->badgeColor('info')
 ->schema([
 // ...
 ]),
 // ...
 ])

As well as allowing a static value, the `badgeColor()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
![Tabs with badges with color](/docs/4.x/images/light/schemas/layout/tabs/badges-color.jpg) ![Tabs with badges with color](/docs/4.x/images/dark/schemas/layout/tabs/badges-color.jpg)

## [#](#using-grid-columns-within-a-tab)Using grid columns within a tab

You may use the `columns()` method to customize the [grid](layouts#grid-system) within the tab:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ])
 ->columns(3),
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
 
## [#](#disabling-scrollable-tabs)Disabling scrollable tabs

Tabs are rendered horizontally by default, and are scrollable when they exceed the available width.

You may disable scrolling using the `scrollable(false)` method:

 use Filament\Schemas\Components\Tabs;
 
 Tabs::make('Tabs')
 ->tabs([
 // ...
 ])
 ->scrollable(false)

When tabs are not scrollable, the component automatically detects the available width. If not all tabs can fit, a dropdown button will appear. Any tabs that exceed the available width will be grouped inside this dropdown automatically.

![Non-scrollable tabs with overflow dropdown](/docs/4.x/images/light/schemas/layout/tabs/not-scrollable.jpg) ![Non-scrollable tabs with overflow dropdown](/docs/4.x/images/dark/schemas/layout/tabs/not-scrollable.jpg)

## [#](#using-vertical-tabs)Using vertical tabs

You can render the tabs vertically by using the `vertical()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 2')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 3')
 ->schema([
 // ...
 ]),
 ])
 ->vertical()

![Vertical tabs](/docs/4.x/images/light/schemas/layout/tabs/vertical.jpg) ![Vertical tabs](/docs/4.x/images/dark/schemas/layout/tabs/vertical.jpg)

Optionally, you can pass a boolean value to the `vertical()` method to control if the tabs should be rendered vertically or not:

 use Filament\Schemas\Components\Tabs;
 
 Tabs::make('Tabs')
 ->tabs([
 // ...
 ])
 ->vertical(FeatureFlag::active())

As well as allowing a static value, the `vertical()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#removing-the-styled-container)Removing the styled container

By default, tabs and their content are wrapped in a container styled as a card. You may remove the styled container using `contained()`:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 2')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 3')
 ->schema([
 // ...
 ]),
 ])
 ->contained(false)

As well as allowing a static value, the `contained()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
## [#](#persisting-the-current-tab-in-the-users-session)Persisting the current tab in the user’s session

By default, the current tab is not persisted in the browser’s local storage. You can change this behavior using the `persistTab()` method. You must also pass in a unique `id()` for the tabs component, to distinguish it from all other sets of tabs in the app. This ID will be used as the key in the local storage to store the current tab:

 use Filament\Schemas\Components\Tabs;
 
 Tabs::make('Tabs')
 ->tabs([
 // ...
 ])
 ->persistTab()
 ->id('order-tabs')

Optionally, the `persistTab()` method accepts a boolean value to control if the active tab should persist or not:

 use Filament\Schemas\Components\Tabs;
 
 Tabs::make('Tabs')
 ->tabs([
 // ...
 ])
 ->persistTab(FeatureFlag::active())
 ->id('order-tabs')

As well as allowing static values, the `persistTab()` and `id()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
 
### [#](#persisting-the-current-tab-in-the-urls-query-string)Persisting the current tab in the URL’s query string

By default, the current tab is not persisted in the URL’s query string. You can change this behavior using the `persistTabInQueryString()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 2')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 3')
 ->schema([
 // ...
 ]),
 ])
 ->persistTabInQueryString()

When enabled, the current tab is persisted in the URL’s query string using the `tab` key. You can change this key by passing it to the `persistTabInQueryString()` method:

 use Filament\Schemas\Components\Tabs;
 use Filament\Schemas\Components\Tabs\Tab;
 
 Tabs::make('Tabs')
 ->tabs([
 Tab::make('Tab 1')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 2')
 ->schema([
 // ...
 ]),
 Tab::make('Tab 3')
 ->schema([
 // ...
 ]),
 ])
 ->persistTabInQueryString('settings-tab')

As well as allowing a static value, the `persistTabInQueryString()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/schemas/overview#component-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Component | `Filament\Schemas\Components\Component` | `$component` | The current component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/schemas/docs/04-tabs.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)