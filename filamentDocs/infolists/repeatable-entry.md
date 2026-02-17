# Source: https://filamentphp.com/docs/4.x/infolists/repeatable-entry

Infolists 

# Repeatable entry 

## [#](#introduction)Introduction

The repeatable entry allows you to repeat a set of entries and layout components for items in an array or relationship.

 use Filament\Infolists\Components\RepeatableEntry;
 use Filament\Infolists\Components\TextEntry;
 
 RepeatableEntry::make('comments')
 ->schema([
 TextEntry::make('author.name'),
 TextEntry::make('title'),
 TextEntry::make('content')
 ->columnSpan(2),
 ])
 ->columns(2)

As you can see, the repeatable entry has an embedded `schema()` which gets repeated for each item.

For example, the state of this entry might be represented as:

 [
 [
 'author' => ['name' => 'Jane Doe'],
 'title' => 'Wow!',
 'content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nullam euismod, nisl eget aliquam ultricies, nunc nisl aliquet nunc, quis aliquam nisl.',
 ],
 [
 'author' => ['name' => 'John Doe'],
 'title' => 'This isn\'t working. Help!',
 'content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nullam euismod, nisl eget aliquam ultricies, nunc nisl aliquet nunc, quis aliquam nisl.',
 ],
 ]

![Repeatable entry](/docs/4.x/images/light/infolists/entries/repeatable/simple.jpg) ![Repeatable entry](/docs/4.x/images/dark/infolists/entries/repeatable/simple.jpg)

Alternatively, `comments` and `author` could be Eloquent relationships, `title` and `content` could be attributes on the comment model, and `name` could be an attribute on the author model. Filament will automatically handle the relationship loading and display the data in the same way.

## [#](#grid-layout)Grid layout

You may organize repeatable items into columns by using the `grid()` method:

 use Filament\Infolists\Components\RepeatableEntry;
 
 RepeatableEntry::make('comments')
 ->schema([
 // ...
 ])
 ->grid(2)

This method accepts the same options as the `columns()` method of the [grid](../schemas/layouts#grid-system). This allows you to responsively customize the number of grid columns at various breakpoints.

As well as allowing a static value, the `grid()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
![Repeatable entry in grid layout](/docs/4.x/images/light/infolists/entries/repeatable/grid.jpg) ![Repeatable entry in grid layout](/docs/4.x/images/dark/infolists/entries/repeatable/grid.jpg)

## [#](#removing-the-styled-container)Removing the styled container

By default, each item in a repeatable entry is wrapped in a container styled as a card. You may remove the styled container using `contained()`:

 use Filament\Infolists\Components\RepeatableEntry;
 
 RepeatableEntry::make('comments')
 ->schema([
 // ...
 ])
 ->contained(false)

As well as allowing a static value, the `contained()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/infolists/overview#entry-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Entry | `Filament\Infolists\Components\Entry` | `$component` | The current entry component instance. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current schema data. Validation is not run on form fields. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the entry. 
 
## [#](#table-repeatable-layout)Table repeatable layout

You can present repeatable items in a table format using the `table()` method, which accepts an array of `TableColumn` objects. These objects represent the columns of the table, which correspond to any components in the schema of the entry:

 use Filament\Infolists\Components\IconEntry;
 use Filament\Infolists\Components\RepeatableEntry;
 use Filament\Infolists\Components\RepeatableEntry\TableColumn;
 use Filament\Infolists\Components\TextEntry;
 
 RepeatableEntry::make('comments')
 ->table([
 TableColumn::make('Author'),
 TableColumn::make('Title'),
 TableColumn::make('Published'),
 ])
 ->schema([
 TextEntry::make('author.name'),
 TextEntry::make('title'),
 IconEntry::make('is_published')
 ->boolean(),
 ])

![Repeatable entry with table layout](/docs/4.x/images/light/infolists/entries/repeatable/table.jpg) ![Repeatable entry with table layout](/docs/4.x/images/dark/infolists/entries/repeatable/table.jpg)

The labels displayed in the header of the table are passed to the `TableColumn::make()` method. If you want to provide an accessible label for a column but do not wish to display it, you can use the `hiddenHeaderLabel()` method:

 use Filament\Infolists\Components\RepeatableEntry\TableColumn;
 
 TableColumn::make('Name')
 ->hiddenHeaderLabel()

You can enable wrapping of the column header using the `wrapHeader()` method:

 use Filament\Infolists\Components\RepeatableEntry\TableColumn;
 
 TableColumn::make('Name')
 ->wrapHeader()

You can also adjust the alignment of the column header using the `alignment()` method, passing an `Alignment` option of `Alignment::Start`, `Alignment::Center`, or `Alignment::End`:

 use Filament\Infolists\Components\RepeatableEntry\TableColumn;
 use Filament\Support\Enums\Alignment;
 
 TableColumn::make('Name')
 ->alignment(Alignment::Center)

You can set a fixed column width using the `width()` method, passing a string value that represents the width of the column. This value is passed directly to the `style` attribute of the column header:

 use Filament\Infolists\Components\RepeatableEntry\TableColumn;
 
 TableColumn::make('Name')
 ->width('200px')

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/infolists/docs/08-repeatable-entry.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)