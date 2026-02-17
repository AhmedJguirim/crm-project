# Source: https://filamentphp.com/docs/4.x/tables/columns/overview

Tables \- Columns 

# Overview 

## [#](#introduction)Introduction

Column classes can be found in the `Filament\Tables\Columns` namespace. They reside within the `$table->columns()` method. Filament includes a number of columns built-in:

 * [Text column](text)
 * [Icon column](icon)
 * [Image column](image)
 * [Color column](color)

Editable columns allow the user to update data in the database without leaving the table:

 * [Select column](select)
 * [Toggle column](toggle)
 * [Text input column](text-input)
 * [Checkbox column](checkbox)

You may also [create your own custom columns](custom-columns) to display data however you wish.

Columns may be created using the static `make()` method, passing its unique name. Usually, the name of a column corresponds to the name of an attribute on an Eloquent model. You may use “dot notation” to access attributes within relationships:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 
 TextColumn::make('author.name')

## [#](#column-content-state)Column content (state)

Columns may feel a bit magic at first, but they’re designed to be simple to use and optimized to display data from an Eloquent record. Despite this, they’re flexible and you can display data from any source, not just an Eloquent record attribute.

The data that a column displays is called its “state”. When using a [panel resource](../../resources/overview), the table is aware of the records it is displaying. This means that the state of the column is set based on the value of the attribute on the record. For example, if the column is used in the table of a `PostResource`, then the `title` attribute value of the current post will be displayed.

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')

If you want to access the value stored in a relationship, you can use “dot notation”. The name of the relationship that you would like to access data from comes first, followed by a dot, and then the name of the attribute:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('author.name')

You can also use “dot notation” to access values within a JSON / array column on an Eloquent model. The name of the attribute comes first, followed by a dot, and then the key of the JSON object you want to read from:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('meta.title')

### [#](#setting-the-state-of-a-column)Setting the state of a column

You can pass your own state to a column by using the `state()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->state('Hello, world!')

The `state()` method also accepts a function to dynamically calculate the state. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#setting-the-default-state-of-a-column)Setting the default state of a column

When a column is empty (its state is `null`), you can use the `default()` method to define alternative state to use instead. This method will treat the default state as if it were real, so columns like [image](image) or [color](color) will display the default image or color.

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->default('Untitled')

#### [#](#adding-placeholder-text-if-a-column-is-empty)Adding placeholder text if a column is empty

Sometimes you may want to display placeholder text for columns with an empty state, which is styled as a lighter gray text. This differs from the [default value](#setting-the-default-state-of-an-column), as the placeholder is always text and not treated as if it were real state.

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->placeholder('Untitled')

![Column with a placeholder for empty state](/docs/4.x/images/light/tables/columns/placeholder.jpg) ![Column with a placeholder for empty state](/docs/4.x/images/dark/tables/columns/placeholder.jpg)

### [#](#displaying-data-from-relationships)Displaying data from relationships

You may use “dot notation” to access columns within relationships. The name of the relationship comes first, followed by a period, followed by the name of the column to display:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('author.name')

#### [#](#counting-relationships)Counting relationships

If you wish to count the number of related records in a column, you may use the `counts()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('users_count')->counts('users')

In this example, `users` is the name of the relationship to count from. The name of the column must be `users_count`, as this is the convention that [Laravel uses](https://laravel.com/docs/eloquent-relationships#counting-related-models) for storing the result.

If you’d like to scope the relationship before counting, you can pass an array to the method, where the key is the relationship name and the value is the function to scope the Eloquent query with:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Database\Eloquent\Builder;
 
 TextColumn::make('users_count')->counts([
 'users' => fn (Builder $query) => $query->where('is_active', true),
 ])

#### [#](#determining-relationship-existence)Determining relationship existence

If you simply wish to indicate whether related records exist in a column, you may use the `exists()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('users_exists')->exists('users')

In this example, `users` is the name of the relationship to check for existence. The name of the column must be `users_exists`, as this is the convention that [Laravel uses](https://laravel.com/docs/eloquent-relationships#other-aggregate-functions) for storing the result.

If you’d like to scope the relationship before checking existence, you can pass an array to the method, where the key is the relationship name and the value is the function to scope the Eloquent query with:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Database\Eloquent\Builder;
 
 TextColumn::make('users_exists')->exists([
 'users' => fn (Builder $query) => $query->where('is_active', true),
 ])

#### [#](#aggregating-relationships)Aggregating relationships

Filament provides several methods for aggregating a relationship field, including `avg()`, `max()`, `min()` and `sum()`. For instance, if you wish to show the average of a field on all related records in a column, you may use the `avg()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('users_avg_age')->avg('users', 'age')

In this example, `users` is the name of the relationship, while `age` is the field that is being averaged. The name of the column must be `users_avg_age`, as this is the convention that [Laravel uses](https://laravel.com/docs/eloquent-relationships#other-aggregate-functions) for storing the result.

If you’d like to scope the relationship before aggregating, you can pass an array to the method, where the key is the relationship name and the value is the function to scope the Eloquent query with:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Database\Eloquent\Builder;
 
 TextColumn::make('users_avg_age')->avg([
 'users' => fn (Builder $query) => $query->where('is_active', true),
 ], 'age')

## [#](#setting-a-columns-label)Setting a column’s label

By default, the label of the column, which is displayed in the header of the table, is generated from the name of the column. You may customize this using the `label()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->label('Full name')

As well as allowing a static value, the `label()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
Customizing the label in this way is useful if you wish to use a [translation string for localization](https://laravel.com/docs/localization#retrieving-translation-strings):

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->label(__('columns.name'))

## [#](#sorting)Sorting

Columns may be sortable, by clicking on the column label. To make a column sortable, you must use the `sortable()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->sortable()

![Table with sortable column](/docs/4.x/images/light/tables/columns/sortable.jpg) ![Table with sortable column](/docs/4.x/images/dark/tables/columns/sortable.jpg)

Using the name of the column, Filament will apply an `orderBy()` clause to the Eloquent query. This is useful for simple cases where the column name matches the database column name. It can also handle [relationships](#displaying-data-from-relationships).

However, many columns are not as simple. The [state](#column-content-state) of the column might be customized, or using an [Eloquent accessor](https://laravel.com/docs/eloquent-mutators#accessors-and-mutators). In this case, you may need to customize the sorting behavior.

You can pass an array of real database columns in the table to sort the column with:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('full_name')
 ->sortable(['first_name', 'last_name'])

In this instance, the `full_name` column is not a real column in the database, but the `first_name` and `last_name` columns are. When the `full_name` column is sorted, Filament will sort the table by the `first_name` and `last_name` columns. The reason why two columns are passed is that if two records have the same `first_name`, the `last_name` will be used to sort them. If your use case doesn’t require this, you can pass only one column in the array if you wish.

You may also directly interact with the Eloquent query to customize how sorting is applied for that column:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Database\Eloquent\Builder;
 
 TextColumn::make('full_name')
 ->sortable(query: function (Builder $query, string $direction): Builder {
 return $query
 ->orderBy('last_name', $direction)
 ->orderBy('first_name', $direction);
 })

The `query` parameter’s function can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Direction | `string` | `$direction` | The direction that the column is currently being sorted on, either `'asc'` or `'desc'`. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent query builder | `Illuminate\Database\Eloquent\Builder` | `$query` | The query builder to modify. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#sorting-by-default)Sorting by default

You may choose to sort a table by default if no other sort is applied. You can use the `defaultSort()` method for this:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->defaultSort('stock', direction: 'desc');
 }

The second parameter is optional and defaults to `'asc'`.

If you pass the name of a table column as the first parameter, Filament will use that column’s sorting behavior (custom sorting columns or query function). However, if you need to sort by a column that doesn’t exist in the table or in the database, you should pass a query function instead:

 use Filament\Tables\Table;
 use Illuminate\Database\Eloquent\Builder;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->defaultSort(function (Builder $query): Builder {
 return $query->orderBy('stock');
 });
 }

### [#](#persisting-the-sort-in-the-users-session)Persisting the sort in the user’s session

To persist the sorting in the user’s session, use the `persistSortInSession()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->persistSortInSession();
 }

### [#](#setting-a-default-sort-option-label)Setting a default sort option label

To set a default sort option label, use the `defaultSortOptionLabel()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->defaultSortOptionLabel('Date');
 }

## [#](#disabling-default-primary-key-sorting)Disabling default primary key sorting

By default, Filament will automatically add a primary key sort to the table query to ensure that the order of records is consistent. The primary key will be sorted in the same direction as the other sort column. If your table doesn’t have a primary key, or you wish to disable this behavior, you can use the `defaultKeySort(false)` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->defaultKeySort(false);
 }

## [#](#searching)Searching

Columns may be searchable by using the text input field in the top right of the table. To make a column searchable, you must use the `searchable()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->searchable()

![Table with searchable column](/docs/4.x/images/light/tables/columns/searchable.jpg) ![Table with searchable column](/docs/4.x/images/dark/tables/columns/searchable.jpg)

By default, Filament will apply a `where` clause to the Eloquent query, searching for the column name. This is useful for simple cases where the column name matches the database column name. It can also handle [relationships](#displaying-data-from-relationships).

However, many columns are not as simple. The [state](#column-content-state) of the column might be customized, or using an [Eloquent accessor](https://laravel.com/docs/eloquent-mutators#accessors-and-mutators). In this case, you may need to customize the search behavior.

You can pass an array of real database columns in the table to search the column with:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('full_name')
 ->searchable(['first_name', 'last_name'])

In this instance, the `full_name` column is not a real column in the database, but the `first_name` and `last_name` columns are. When the `full_name` column is searched, Filament will search the table by the `first_name` and `last_name` columns.

You may also directly interact with the Eloquent query to customize how searching is applied for that column:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Database\Eloquent\Builder;
 
 TextColumn::make('full_name')
 ->searchable(query: function (Builder $query, string $search): Builder {
 return $query
 ->where('first_name', 'like', "%{$search}%")
 ->orWhere('last_name', 'like', "%{$search}%");
 })

The `query` parameter’s function can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent query builder | `Illuminate\Database\Eloquent\Builder` | `$query` | The query builder to modify. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
Search | `string` | `$search` | The current search input value. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#adding-extra-searchable-columns-to-the-table)Adding extra searchable columns to the table

You may allow the table to search with extra columns that aren’t present in the table by passing an array of column names to the `searchable()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->searchable(['id']);
 }

You may use dot notation to search within relationships:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->searchable(['id', 'author.id']);
 }

You may also pass custom functions to search using:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->searchable([
 'id',
 'author.id',
 function (Builder $query, string $search): Builder {
 if (! is_numeric($search)) {
 return $query;
 }
 
 return $query->whereYear('published_at', $search);
 },
 ]);
 }

### [#](#customizing-the-table-search-field-placeholder)Customizing the table search field placeholder

You may customize the placeholder in the search field using the `searchPlaceholder()` method on the `$table`:

 use Filament\Tables\Table;
 
 public static function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->searchPlaceholder('Search (ID, Name)');
 }

### [#](#searching-individually)Searching individually

You can choose to enable a per-column search input field using the `isIndividual` parameter:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->searchable(isIndividual: true)

![Table with individually searchable column](/docs/4.x/images/light/tables/columns/individually-searchable.jpg) ![Table with individually searchable column](/docs/4.x/images/dark/tables/columns/individually-searchable.jpg)

If you use the `isIndividual` parameter, you may still search that column using the main “global” search input field for the entire table.

To disable that functionality while still preserving the individual search functionality, you need the `isGlobal` parameter:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->searchable(isIndividual: true, isGlobal: false)

### [#](#customizing-the-table-search-debounce)Customizing the table search debounce

You may customize the debounce time in all table search fields using the `searchDebounce()` method on the `$table`. By default, it is set to `500ms`:

 use Filament\Tables\Table;
 
 public static function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->searchDebounce('750ms');
 }

### [#](#searching-when-the-input-is-blurred)Searching when the input is blurred

Instead of automatically reloading the table contents while the user is typing their search, which is affected by the [debounce](#customizing-the-table-search-debounce) of the search field, you may change the behavior so that the table is only searched when the user blurs the input (tabs or clicks out of it), using the `searchOnBlur()` method:

 use Filament\Tables\Table;
 
 public static function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->searchOnBlur();
 }

### [#](#persisting-the-search-in-the-users-session)Persisting the search in the user’s session

To persist the table or individual column search in the user’s session, use the `persistSearchInSession()` or `persistColumnSearchInSession()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->persistSearchInSession()
 ->persistColumnSearchesInSession();
 }

### [#](#disabling-search-term-splitting)Disabling search term splitting

By default, the table search will split the search term into individual words and search for each word separately. This allows for more flexible search queries. However, it can have a negative impact on performance when large datasets are involved. You can disable this behavior using the `splitSearchTerms(false)` method on the table:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->splitSearchTerms(false);
 }

## [#](#clickable-cell-content)Clickable cell content

When a cell is clicked, you may open a URL or trigger an “action”.

### [#](#opening-urls)Opening URLs

To open a URL, you may use the `url()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->url(fn (Post $record): string => route('posts.edit', ['post' => $record]))

The `url()` method also accepts a function to dynamically calculate the value. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
TIP

You can also pick a URL for the entire row to open, not just a singular column. Please see the [Record URLs section](../overview#record-urls-clickable-rows).

When using a record URL and a column URL, the column URL will override the record URL for those cells only.

You may also choose to open the URL in a new tab:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->url(fn (Post $record): string => route('posts.edit', ['post' => $record]))
 ->openUrlInNewTab()

### [#](#triggering-actions)Triggering actions

To run a function when a cell is clicked, you may use the `action()` method. Each method accepts a `$record` parameter which you may use to customize the behavior of the action:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->action(function (Post $record): void {
 $this->dispatch('open-post-edit-modal', post: $record->getKey());
 })

#### [#](#action-modals)Action modals

You may open [action modals](../../actions#modals) by passing in an `Action` object to the `action()` method:

 use Filament\Actions\Action;
 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->action(
 Action::make('select')
 ->requiresConfirmation()
 ->action(function (Post $record): void {
 $this->dispatch('select-post', post: $record->getKey());
 }),
 )

Action objects passed into the `action()` method must have a unique name to distinguish it from other actions within the table.

#### [#](#preventing-cells-from-being-clicked)Preventing cells from being clicked

You may prevent a cell from being clicked by using the `disabledClick()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->disabledClick()

If [row URLs](../overview#record-urls-clickable-rows) are enabled, the cell will not be clickable.

## [#](#adding-a-tooltip-to-a-column)Adding a tooltip to a column

You may specify a tooltip to display when you hover over a cell:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->tooltip('Title')

As well as allowing a static value, the `tooltip()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Table with column triggering a tooltip](/docs/4.x/images/light/tables/columns/tooltips.jpg) ![Table with column triggering a tooltip](/docs/4.x/images/dark/tables/columns/tooltips.jpg)

## [#](#adding-a-header-tooltip-to-a-column)Adding a header tooltip to a column

You may specify a tooltip to display when you hover over the column header:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('sku')
 ->headerTooltip('Stock Keeping Unit')

As well as allowing a static value, the `headerTooltip()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#aligning-column-content)Aligning column content

### [#](#horizontally-aligning-column-content)Horizontally aligning column content

You may align the content of an column to the start (left in left-to-right interfaces, right in right-to-left interfaces), center, or end (right in left-to-right interfaces, left in right-to-left interfaces) using the `alignStart()`, `alignCenter()` or `alignEnd()` methods:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->alignStart() // This is the default alignment.
 
 TextColumn::make('email')
 ->alignCenter()
 
 TextColumn::make('email')
 ->alignEnd()

Alternatively, you may pass an `Alignment` enum to the `alignment()` method:

 use Filament\Support\Enums\Alignment;
 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->label('Email address')
 ->alignment(Alignment::End)

As well as allowing a static value, the `alignment()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Table with column aligned to the end](/docs/4.x/images/light/tables/columns/alignment.jpg) ![Table with column aligned to the end](/docs/4.x/images/dark/tables/columns/alignment.jpg)

### [#](#vertically-aligning-column-content)Vertically aligning column content

You may align the content of a column to the start, center, or end using the `verticallyAlignStart()`, `verticallyAlignCenter()` or `verticallyAlignEnd()` methods:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->verticallyAlignStart()
 
 TextColumn::make('name')
 ->verticallyAlignCenter() // This is the default alignment.
 
 TextColumn::make('name')
 ->verticallyAlignEnd()

Alternatively, you may pass a `VerticalAlignment` enum to the `verticalAlignment()` method:

 use Filament\Support\Enums\VerticalAlignment;
 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->verticalAlignment(VerticalAlignment::Start)

As well as allowing a static value, the `verticalAlignment()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Table with column vertically aligned to the start](/docs/4.x/images/light/tables/columns/vertical-alignment.jpg) ![Table with column vertically aligned to the start](/docs/4.x/images/dark/tables/columns/vertical-alignment.jpg)

## [#](#allowing-column-headers-to-wrap)Allowing column headers to wrap

By default, column headers will not wrap onto multiple lines if they need more space. You may allow them to wrap using the `wrapHeader()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->wrapHeader()

Optionally, you may pass a boolean value to control if the header should wrap:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->wrapHeader(FeatureFlag::active())

The `wrapHeader()` method also accepts a function to dynamically calculate the value. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#controlling-the-width-of-columns)Controlling the width of columns

By default, columns will take up as much space as they need. You may allow some columns to consume more space than others by using the `grow()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->grow()

Alternatively, you can define a width for the column, which is passed to the header cell using the `style` attribute, so you can use any valid CSS value:

 use Filament\Tables\Columns\IconColumn;
 
 IconColumn::make('is_paid')
 ->label('Paid')
 ->boolean()
 ->width('1%')

The `width()` method also accepts a function to dynamically calculate the value. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#grouping-columns)Grouping columns

You group multiple columns together underneath a single heading using a `ColumnGroup` object:

 use Filament\Tables\Columns\ColumnGroup;
 use Filament\Tables\Columns\IconColumn;
 use Filament\Tables\Columns\TextColumn;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 TextColumn::make('title'),
 TextColumn::make('slug'),
 ColumnGroup::make('Visibility', [
 TextColumn::make('status'),
 IconColumn::make('is_featured'),
 ]),
 TextColumn::make('author.name'),
 ]);
 }

The first argument is the label of the group, and the second is an array of column objects that belong to that group.

![Table with grouped columns](/docs/4.x/images/light/tables/columns/grouping.jpg) ![Table with grouped columns](/docs/4.x/images/dark/tables/columns/grouping.jpg)

You can also control the group header [alignment](#horizontally-aligning-column-content) and [wrapping](#allowing-column-headers-to-wrap) on the `ColumnGroup` object. To improve the multi-line fluency of the API, you can chain the `columns()` onto the object instead of passing it as the second argument:

 use Filament\Support\Enums\Alignment;
 use Filament\Tables\Columns\ColumnGroup;
 
 ColumnGroup::make('Website visibility')
 ->columns([
 // ...
 ])
 ->alignCenter()
 ->wrapHeader()

## [#](#hiding-columns)Hiding columns

You may hide a column by using the `hidden()` or `visible()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->hidden()
 
 TextColumn::make('email')
 ->visible()

To hide a column conditionally, you may pass a boolean value to either method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('role')
 ->hidden(FeatureFlag::active())
 
 TextColumn::make('role')
 ->visible(FeatureFlag::active())

### [#](#allowing-users-to-manage-columns)Allowing users to manage columns

#### [#](#toggling-column-visibility)Toggling column visibility

Users may hide or show columns themselves in the table. To make a column toggleable, use the `toggleable()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->toggleable()

![Table with column manager](/docs/4.x/images/light/tables/columns/column-manager.jpg) ![Table with column manager](/docs/4.x/images/dark/tables/columns/column-manager.jpg)

##### [#](#making-toggleable-columns-hidden-by-default)Making toggleable columns hidden by default

By default, toggleable columns are visible. To make them hidden instead:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('id')
 ->toggleable(isToggledHiddenByDefault: true)

#### [#](#reordering-columns)Reordering columns

You may allow columns to be reordered in the table using the `reorderableColumns()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->reorderableColumns();
 }

![Table with reorderable column manager](/docs/4.x/images/light/tables/columns/column-manager-reorderable.jpg) ![Table with reorderable column manager](/docs/4.x/images/dark/tables/columns/column-manager-reorderable.jpg)

#### [#](#live-column-manager)Live column manager

By default, column manager changes (toggling and reordering columns) are deferred and do not affect the table, until the user clicks an “Apply” button. To disable this and make the filters “live” instead, use the `deferColumnManager(false)` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->reorderableColumns()
 ->deferColumnManager(false);
 }

#### [#](#customizing-the-column-manager-dropdown-trigger-action)Customizing the column manager dropdown trigger action

To customize the column manager dropdown trigger button, you may use the `columnManagerTriggerAction()` method, passing a closure that returns an action. All methods that are available to [customize action trigger buttons](../../actions/overview) can be used:

 use Filament\Actions\Action;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->columnManagerTriggerAction(
 fn (Action $action) => $action
 ->button()
 ->label('Column Manager'),
 );
 }

#### [#](#displaying-the-reset-action-in-the-footer)Displaying the reset action in the footer

By default, the reset action appears in the header of the column manager. You may move it to the footer, next to the apply action, using the `columnManagerResetActionPosition()` method:

 use Filament\Tables\Enums\ColumnManagerResetActionPosition;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->columnManagerResetActionPosition(ColumnManagerResetActionPosition::Footer);
 }

#### [#](#disabling-column-persistence-in-the-users-session)Disabling column persistence in the user’s session

By default, Filament persists the table’s columns by storing them in the user’s session. To prevent persisting the columns in the user’s session, use the `persistColumnsInSession(false)` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->persistColumnsInSession(false);
 }

#### [#](#changing-the-number-of-display-columns-in-the-column-manager)Changing the number of display columns in the column manager

By default, the column manager displays its options in a single column. You can increase this to multiple columns using the `columnManagerColumns()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->columns([
 // ...
 ])
 ->columnManagerColumns(2);
 }

## [#](#adding-extra-html-attributes-to-a-column-content)Adding extra HTML attributes to a column content

You can pass extra HTML attributes to the column content via the `extraAttributes()` method, which will be merged onto its outer HTML element. The attributes should be represented by an array, where the key is the attribute name and the value is the attribute value:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('slug')
 ->extraAttributes(['class' => 'slug-column'])

As well as allowing a static value, the `extraAttributes()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, calling `extraAttributes()` multiple times will overwrite the previous attributes. If you wish to merge the attributes instead, you can pass `merge: true` to the method.

### [#](#adding-extra-html-attributes-to-the-cell)Adding extra HTML attributes to the cell

You can also pass extra HTML attributes to the table cell which surrounds the content of the column:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('slug')
 ->extraCellAttributes(['class' => 'slug-cell'])

As well as allowing a static value, the `extraCellAttributes()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, calling `extraCellAttributes()` multiple times will overwrite the previous attributes. If you wish to merge the attributes instead, you can pass `merge: true` to the method.

### [#](#adding-extra-attributes-to-the-header-cell)Adding extra attributes to the header cell

You can pass extra HTML attributes to the table header cell which surrounds the content of the column:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('slug')
 ->extraHeaderAttributes(['class' => 'slug-header-cell'])

As well as allowing a static value, the `extraHeaderAttributes()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, calling `extraHeaderAttributes()` multiple times will overwrite the previous attributes. If you wish to merge the attributes instead, you can pass `merge: true` to the method.

## [#](#column-utility-injection)Column utility injection

The vast majority of methods used to configure columns accept functions as parameters instead of hardcoded values:

 use App\Models\User;
 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->placeholder(fn (User $record): string => "No email for {$record->name}")
 
 TextColumn::make('role')
 ->badge(fn (User $record): bool => $record->role === 'admin')
 
 TextColumn::make('name')
 ->extraAttributes(fn (User $record): array => ['class' => "{$record->getKey()}-name-column"])

This alone unlocks many customization possibilities.

The package is also able to inject many utilities to use inside these functions, as parameters. All customization methods that accept functions as arguments can inject utilities.

These injected utilities require specific parameter names to be used. Otherwise, Filament doesn’t know what to inject.

### [#](#injecting-the-current-state-of-the-column)Injecting the current state of the column

If you wish to access the current [value (state)](#column-content-state) of the column, define a `$state` parameter:

 function ($state) {
 // ...
 }

### [#](#injecting-the-current-eloquent-record)Injecting the current Eloquent record

You may retrieve the Eloquent record for the current schema using a `$record` parameter:

 use Illuminate\Database\Eloquent\Model;
 
 function (?Model $record) {
 // ...
 }

### [#](#injecting-the-row-loop)Injecting the row loop

To access the [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row, define a `$rowLoop` parameter:

 function (stdClass $rowLoop) {
 // ...
 }

### [#](#injecting-the-current-livewire-component-instance)Injecting the current Livewire component instance

If you wish to access the current Livewire component instance, define a `$livewire` parameter:

 use Livewire\Component;
 
 function (Component $livewire) {
 // ...
 }

### [#](#injecting-the-current-column-instance)Injecting the current column instance

If you wish to access the current component instance, define a `$component` parameter:

 use Filament\Tables\Columns\Column;
 
 function (Column $component) {
 // ...
 }

### [#](#injecting-the-current-table-instance)Injecting the current table instance

If you wish to access the current table instance, define a `$table` parameter:

 use Filament\Tables\Table;
 
 function (Table $table) {
 // ...
 }

### [#](#injecting-multiple-utilities)Injecting multiple utilities

The parameters are injected dynamically using reflection, so you’re able to combine multiple parameters in any order:

 use App\Models\User;
 use Livewire\Component as Livewire;
 
 function (Livewire $livewire, mixed $state, User $record) {
 // ...
 }

### [#](#injecting-dependencies-from-laravels-container)Injecting dependencies from Laravel’s container

You may inject anything from Laravel’s container like normal, alongside utilities:

 use App\Models\User;
 use Illuminate\Http\Request;
 
 function (Request $request, User $record) {
 // ...
 }

## [#](#global-settings)Global settings

If you wish to change the default behavior of all columns globally, then you can call the static `configureUsing()` method inside a service provider’s `boot()` method, to which you pass a Closure to modify the columns using. For example, if you wish to make all `TextColumn` columns [`toggleable()`](#toggling-column-visibility), you can do it like so:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::configureUsing(function (TextColumn $column): void {
 $column->toggleable();
 });

Of course, you’re still able to overwrite this on each column individually:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('name')
 ->toggleable(false)

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/01-overview.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)