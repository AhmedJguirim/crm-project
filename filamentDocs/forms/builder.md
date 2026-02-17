# Source: https://filamentphp.com/docs/4.x/forms/builder

Forms 

# Builder 

## [#](#introduction)Introduction

Similar to a [repeater](repeater), the builder component allows you to output a JSON array of repeated form components. Unlike the repeater, which only defines one form schema to repeat, the builder allows you to define different schema “blocks”, which you can repeat in any order. This makes it useful for building more advanced array structures.

The primary use of the builder component is to build web page content using predefined blocks. This could be content for a marketing website, or maybe even fields in an online form. The example below defines multiple blocks for different elements in the page content. On the frontend of your website, you could loop through each block in the JSON and format it how you wish.

 use Filament\Forms\Components\Builder;
 use Filament\Forms\Components\Builder\Block;
 use Filament\Forms\Components\FileUpload;
 use Filament\Forms\Components\Select;
 use Filament\Forms\Components\Textarea;
 use Filament\Forms\Components\TextInput;
 
 Builder::make('content')
 ->blocks([
 Block::make('heading')
 ->schema([
 TextInput::make('content')
 ->label('Heading')
 ->required(),
 Select::make('level')
 ->options([
 'h1' => 'Heading 1',
 'h2' => 'Heading 2',
 'h3' => 'Heading 3',
 'h4' => 'Heading 4',
 'h5' => 'Heading 5',
 'h6' => 'Heading 6',
 ])
 ->required(),
 ])
 ->columns(2),
 Block::make('paragraph')
 ->schema([
 Textarea::make('content')
 ->label('Paragraph')
 ->required(),
 ]),
 Block::make('image')
 ->schema([
 FileUpload::make('url')
 ->label('Image')
 ->image()
 ->required(),
 TextInput::make('alt')
 ->label('Alt text')
 ->required(),
 ]),
 ])

![Builder](/docs/4.x/images/light/forms/fields/builder/simple.jpg) ![Builder](/docs/4.x/images/dark/forms/fields/builder/simple.jpg)

We recommend that you store builder data with a `JSON` column in your database. Additionally, if you’re using Eloquent, make sure that column has an `array` cast.

As evident in the above example, blocks can be defined within the `blocks()` method of the component. Blocks are `Builder\Block` objects, and require a unique name, and a component schema:

 use Filament\Forms\Components\Builder;
 use Filament\Forms\Components\Builder\Block;
 use Filament\Forms\Components\TextInput;
 
 Builder::make('content')
 ->blocks([
 Block::make('heading')
 ->schema([
 TextInput::make('content')->required(),
 // ...
 ]),
 // ...
 ])

## [#](#setting-a-blocks-label)Setting a block’s label

By default, the label of the block will be automatically determined based on its name. To override the block’s label, you may use the `label()` method. Customizing the label in this way is useful if you wish to use a [translation string for localization](https://laravel.com/docs/localization#retrieving-translation-strings):

 use Filament\Forms\Components\Builder\Block;
 
 Block::make('heading')
 ->label(__('blocks.heading'))

### [#](#labelling-builder-items-based-on-their-content)Labelling builder items based on their content

You may add a label for a builder item using the same `label()` method. This method accepts a closure that receives the item’s data in a `$state` variable. If `$state` is null, you should return the block label that should be displayed in the block picker. Otherwise, you should return a string to be used as the item label:

 use Filament\Forms\Components\Builder\Block;
 use Filament\Forms\Components\TextInput;
 
 Block::make('heading')
 ->schema([
 TextInput::make('content')
 ->live(onBlur: true)
 ->required(),
 // ...
 ])
 ->label(function (?array $state): string {
 if ($state === null) {
 return 'Heading';
 }
 
 return $state['content'] ?? 'Untitled heading';
 })

Any fields that you use from `$state` should be `live()` if you wish to see the item label update live as you use the form.

You can inject various utilities into the function passed to `label()` as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Key | `string` | `$key` | The key for the current block. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `array<string, mixed>` | `$state` | The raw unvalidated data for the current block. 
 
![Builder with labelled blocks based on the content](/docs/4.x/images/light/forms/fields/builder/labelled.jpg) ![Builder with labelled blocks based on the content](/docs/4.x/images/dark/forms/fields/builder/labelled.jpg)

### [#](#numbering-builder-items)Numbering builder items

By default, items in the builder have a number next to their label. You may disable this using the `blockNumbers(false)` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->blockNumbers(false)

As well as allowing a static value, the `blockNumbers()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#setting-a-blocks-icon)Setting a block’s icon

Blocks may also have an [icon](../styling/icons), which is displayed next to the label. You can add an icon by passing its name to the `icon()` method:

 use Filament\Forms\Components\Builder\Block;
 use Filament\Support\Icons\Heroicon;
 
 Block::make('paragraph')
 ->icon(Heroicon::Bars3BottomLeft)

As well as allowing a static value, the `icon()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Builder with block icons in the dropdown](/docs/4.x/images/light/forms/fields/builder/icons.jpg) ![Builder with block icons in the dropdown](/docs/4.x/images/dark/forms/fields/builder/icons.jpg)

### [#](#adding-icons-to-the-header-of-blocks)Adding icons to the header of blocks

By default, blocks in the builder don’t have an icon next to the header label, just in the dropdown to add new blocks. You may enable this using the `blockIcons()` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->blockIcons()

Optionally, you may pass a boolean value to the `blockIcons()` method to control if the icons are displayed in the block headers:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->blockIcons(FeatureFlag::active())

As well as allowing a static value, the `blockIcons()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#previewing-blocks)Previewing blocks

If you prefer to render read-only previews in the builder instead of the blocks’ forms, you can use the `blockPreviews()` method. This will render each block’s `preview()` instead of the form. Block data will be passed to the preview Blade view in a variable with the same name:

 use Filament\Forms\Components\Builder;
 use Filament\Forms\Components\Builder\Block;
 use Filament\Forms\Components\TextInput;
 
 Builder::make('content')
 ->blockPreviews()
 ->blocks([
 Block::make('heading')
 ->schema([
 TextInput::make('text')
 ->placeholder('Default heading'),
 ])
 ->preview('filament.content.block-previews.heading'),
 ])

In `/resources/views/filament/content/block-previews/heading.blade.php`, you can access the block data like so:

 <h1>
 {{ $text ?? 'Default heading' }}
 </h1>

Optionally, the `blockPreviews()` method accepts a boolean value to control if the builder should render block previews or not:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->blockPreviews(FeatureFlag::active())

As well as allowing static values, the `blockPreviews()` and `preview()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#interactive-block-previews)Interactive block previews

By default, preview content is not interactive, and clicking it will open the Edit modal for that block to manage its settings. If you have links and buttons that you’d like to remain interactive in the block previews, you can use the `areInteractive: true` argument of the `blockPreviews()` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blockPreviews(areInteractive: true)
 ->blocks([
 //
 ])

As well as allowing a static value, the `areInteractive` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#adding-items)Adding items

An action button is displayed below the builder to allow the user to add a new item.

## [#](#setting-the-add-action-buttons-label)Setting the add action button’s label

You may set a label to customize the text that should be displayed in the button for adding a builder item, using the `addActionLabel()` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->addActionLabel('Add a new block')

As well as allowing a static value, the `addActionLabel()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#aligning-the-add-action-button)Aligning the add action button

By default, the add action is aligned in the center. You may adjust this using the `addActionAlignment()` method, passing an `Alignment` option of `Alignment::Start` or `Alignment::End`:

 use Filament\Forms\Components\Builder;
 use Filament\Support\Enums\Alignment;
 
 Builder::make('content')
 ->schema([
 // ...
 ])
 ->addActionAlignment(Alignment::Start)

As well as allowing a static value, the `addActionAlignment()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#preventing-the-user-from-adding-items)Preventing the user from adding items

You may prevent the user from adding items to the builder using the `addable(false)` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->addable(false)

As well as allowing a static value, the `addable()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#deleting-items)Deleting items

An action button is displayed on each item to allow the user to delete it.

### [#](#preventing-the-user-from-deleting-items)Preventing the user from deleting items

You may prevent the user from deleting items from the builder using the `deletable(false)` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->deletable(false)

As well as allowing a static value, the `deletable()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#reordering-items)Reordering items

A button is displayed on each item to allow the user to drag and drop to reorder it in the list.

### [#](#preventing-the-user-from-reordering-items)Preventing the user from reordering items

You may prevent the user from reordering items from the builder using the `reorderable(false)` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->reorderable(false)

As well as allowing a static value, the `reorderable()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#reordering-items-with-buttons)Reordering items with buttons

You may use the `reorderableWithButtons()` method to enable reordering items with buttons to move the item up and down:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->reorderableWithButtons()

![Builder that is reorderable with buttons](/docs/4.x/images/light/forms/fields/builder/reorderable-with-buttons.jpg) ![Builder that is reorderable with buttons](/docs/4.x/images/dark/forms/fields/builder/reorderable-with-buttons.jpg)

Optionally, you may pass a boolean value to control if the builder should be ordered with buttons or not:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->reorderableWithButtons(FeatureFlag::active())

As well as allowing a static value, the `reorderableWithButtons()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#preventing-reordering-with-drag-and-drop)Preventing reordering with drag and drop

You may use the `reorderableWithDragAndDrop(false)` method to prevent items from being ordered with drag and drop:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->reorderableWithDragAndDrop(false)

As well as allowing a static value, the `reorderableWithDragAndDrop()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#collapsing-items)Collapsing items

The builder may be `collapsible()` to optionally hide content in long forms:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->collapsible()

You may also collapse all items by default:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->collapsed()

![Collapsed builder](/docs/4.x/images/light/forms/fields/builder/collapsed.jpg) ![Collapsed builder](/docs/4.x/images/dark/forms/fields/builder/collapsed.jpg)

Optionally, the `collapsible()` and `collapsed()` methods accept a boolean value to control if the builder should be collapsible and collapsed or not:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->collapsible(FeatureFlag::active())
 ->collapsed(FeatureFlag::active())

As well as allowing static values, the `collapsible()` and `collapsed()` methods also accept functions to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#cloning-items)Cloning items

You may allow builder items to be duplicated using the `cloneable()` method:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->cloneable()

![Cloneable repeater](/docs/4.x/images/light/forms/fields/builder/cloneable.jpg) ![Cloneable repeater](/docs/4.x/images/dark/forms/fields/builder/cloneable.jpg)

## [#](#customizing-the-block-picker)Customizing the block picker

### [#](#changing-the-number-of-columns-in-the-block-picker)Changing the number of columns in the block picker

The block picker has only 1 column. You may customize it by passing a number of columns to `blockPickerColumns()`:

 use Filament\Forms\Components\Builder;
 
 Builder::make()
 ->blockPickerColumns(2)
 ->blocks([
 // ...
 ])

This method can be used in a couple of different ways:

 * You can pass an integer like `blockPickerColumns(2)`. This integer is the number of columns used on the `lg` breakpoint and higher. All smaller devices will have just 1 column.
 * You can pass an array, where the key is the breakpoint and the value is the number of columns. For example, `blockPickerColumns(['md' => 2, 'xl' => 4])` will create a 2 column layout on medium devices, and a 4 column layout on extra large devices. The default breakpoint for smaller devices uses 1 column, unless you use a `default` array key.

Breakpoints (`sm`, `md`, `lg`, `xl`, `2xl`) are defined by Tailwind, and can be found in the [Tailwind documentation](https://tailwindcss.com/docs/responsive-design#overview).

As well as allowing a static value, the `blockPickerColumns()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
### [#](#increasing-the-width-of-the-block-picker)Increasing the width of the block picker

When you [increase the number of columns](#changing-the-number-of-columns-in-the-block-picker), the width of the dropdown should increase incrementally to handle the additional columns. If you’d like more control, you can manually set a maximum width for the dropdown using the `blockPickerWidth()` method. Options correspond to [Tailwind’s max-width scale](https://tailwindcss.com/docs/max-width). The options are `xs`, `sm`, `md`, `lg`, `xl`, `2xl`, `3xl`, `4xl`, `5xl`, `6xl`, `7xl`:

 use Filament\Forms\Components\Builder;
 
 Builder::make()
 ->blockPickerColumns(3)
 ->blockPickerWidth('2xl')
 ->blocks([
 // ...
 ])

As well as allowing a static value, the `blockPickerWidth()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#limiting-the-number-of-times-a-block-can-be-used)Limiting the number of times a block can be used

By default, each block can be used in the builder an unlimited number of times. You may limit this using the `maxItems()` method on a block:

 use Filament\Forms\Components\Builder\Block;
 
 Block::make('heading')
 ->schema([
 // ...
 ])
 ->maxItems(1)

As well as allowing a static value, the `maxItems()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#using-get-to-access-parent-field-values)Using `$get()` to access parent field values

All form components are able to [use `$get()` and `$set()`](overview#injecting-the-state-of-another-field) to access another field’s value. However, you might experience unexpected behavior when using this inside the builder’s schema.

This is because `$get()` and `$set()`, by default, are scoped to the current builder item. This means that you are able to interact with another field inside that builder item easily without knowing which builder item the current form component belongs to.

The consequence of this is that you may be confused when you are unable to interact with a field outside the builder. We use `../` syntax to solve this problem - `$get('../parent_field_name')`.

Consider your form has this data structure:

 [
 'client_id' => 1,
 
 'builder' => [
 'item1' => [
 'service_id' => 2,
 ],
 ],
 ]

You are trying to retrieve the value of `client_id` from inside the builder item.

`$get()` is relative to the current builder item, so `$get('client_id')` is looking for `$get('builder.item1.client_id')`.

You can use `../` to go up a level in the data structure, so `$get('../client_id')` is `$get('builder.client_id')` and `$get('../client_id')` is `$get('client_id')`.

The special case of `$get()` with no arguments, or `$get('')` or `$get('./')`, will always return the full data array for the current builder item.

## [#](#builder-validation)Builder validation

As well as all rules listed on the [validation](validation) page, there are additional rules that are specific to builders.

### [#](#number-of-items-validation)Number of items validation

You can validate the minimum and maximum number of items that you can have in a builder by setting the `minItems()` and `maxItems()` methods:

 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->minItems(1)
 ->maxItems(5)

As well as allowing static values, the `minItems()` and `maxItems()` methods also accept a function to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
## [#](#customizing-the-builder-item-actions)Customizing the builder item actions

This field uses action objects for easy customization of buttons within it. You can customize these buttons by passing a function to an action registration method. The function has access to the `$action` object, which you can use to [customize it](../actions/overview). The following methods are available to customize the actions:

 * `addAction()`
 * `addBetweenAction()`
 * `cloneAction()`
 * `collapseAction()`
 * `collapseAllAction()`
 * `deleteAction()`
 * `expandAction()`
 * `expandAllAction()`
 * `moveDownAction()`
 * `moveUpAction()`
 * `reorderAction()`

Here is an example of how you might customize an action:

 use Filament\Actions\Action;
 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->collapseAllAction(
 fn (Action $action) => $action->label('Collapse all content'),
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
 
### [#](#confirming-builder-actions-with-a-modal)Confirming builder actions with a modal

You can confirm actions with a modal by using the `requiresConfirmation()` method on the action object. You may use any [modal customization method](../actions/modals) to change its content and behavior:

 use Filament\Actions\Action;
 use Filament\Forms\Components\Builder;
 
 Builder::make('content')
 ->blocks([
 // ...
 ])
 ->deleteAction(
 fn (Action $action) => $action->requiresConfirmation(),
 )

NOTE

The `addAction()`, `addBetweenAction()`, `collapseAction()`, `collapseAllAction()`, `expandAction()`, `expandAllAction()` and `reorderAction()` methods do not support confirmation modals, as clicking their buttons does not make the network request that is required to show the modal.

### [#](#adding-extra-item-actions-to-a-builder)Adding extra item actions to a builder

You may add new [action buttons](../actions) to the header of each builder item by passing `Action` objects into `extraItemActions()`:

 use Filament\Actions\Action;
 use Filament\Forms\Components\Builder;
 use Filament\Forms\Components\Builder\Block;
 use Filament\Forms\Components\TextInput;
 use Filament\Support\Icons\Heroicon;
 use Illuminate\Support\Facades\Mail;
 
 Builder::make('content')
 ->blocks([
 Block::make('contactDetails')
 ->schema([
 TextInput::make('email')
 ->label('Email address')
 ->email()
 ->required(),
 // ...
 ]),
 // ...
 ])
 ->extraItemActions([
 Action::make('sendEmail')
 ->icon(Heroicon::Square2Stack)
 ->action(function (array $arguments, Builder $component): void {
 $itemData = $component->getItemState($arguments['item']);
 
 Mail::to($itemData['email'])
 ->send(
 // ...
 );
 }),
 ])

In this example, `$arguments['item']` gives you the ID of the current builder item. You can validate the data in that builder item using the `getItemState()` method on the builder component. This method returns the validated data for the item. If the item is not valid, it will cancel the action and show an error message for that item in the form.

If you want to get the raw data from the current item without validating it, you can use `$component->getRawItemState($arguments['item'])` instead.

If you want to manipulate the raw data for the entire builder, for example, to add, remove or modify items, you can use `$component->getState()` to get the data, and `$component->state($state)` to set it again:

 use Illuminate\Support\Str;
 
 // Get the raw data for the entire builder
 $state = $component->getState();
 
 // Add an item, with a random UUID as the key
 $state[Str::uuid()] = [
 'type' => 'contactDetails',
 'data' => [
 'email' => auth()->user()->email,
 ],
 ];
 
 // Set the new data for the builder
 $component->state($state);

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/forms/docs/13-builder.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)