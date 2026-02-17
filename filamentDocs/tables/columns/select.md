# Source: https://filamentphp.com/docs/4.x/tables/columns/select

Tables \- Columns 

# Select column 

## [#](#introduction)Introduction

The select column allows you to render a select field inside the table, which can be used to update that database record without needing to open a new page or a modal.

You must pass options to the column:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])

![Select column](/docs/4.x/images/light/tables/columns/select/simple.jpg) ![Select column](/docs/4.x/images/dark/tables/columns/select/simple.jpg)

## [#](#enabling-the-javascript-select)Enabling the JavaScript select

By default, Filament uses the native HTML5 select. You may enable a more customizable JavaScript select using the `native(false)` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->native(false)

As well as allowing a static value, the `native()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#searching-options)Searching options

You may enable a search input to allow easier access to many options, using the `searchableOptions()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->label('Author')
 ->options(User::query()->pluck('name', 'id'))
 ->searchableOptions()

Optionally, you may pass a boolean value to control if the input should be searchable or not:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->label('Author')
 ->options(User::query()->pluck('name', 'id'))
 ->searchableOptions(FeatureFlag::active())

As well as allowing a static value, the `searchableOptions()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#returning-custom-search-results)Returning custom search results

If you have lots of options and want to populate them based on a database search or other external data source, you can use the `getOptionsSearchResultsUsing()` and `getOptionLabelUsing()` methods instead of `options()`.

The `getOptionsSearchResultsUsing()` method accepts a callback that returns search results in `$key => $value` format. The current user’s search is available as `$search`, and you should use that to filter your results.

The `getOptionLabelUsing()` method accepts a callback that transforms the selected option `$value` into a label. This is used when the form is first loaded when the user has not made a search yet. Otherwise, the label used to display the currently selected option would not be available.

Both `getOptionsSearchResultsUsing()` and `getOptionLabelUsing()` must be used on the select if you want to provide custom search results:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->searchableOptions()
 ->getOptionsSearchResultsUsing(fn (string $search): array => User::query()
 ->where('name', 'like', "%{$search}%")
 ->limit(50)
 ->pluck('name', 'id')
 ->all())
 ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->name),

`getOptionLabelUsing()` is crucial, since it provides Filament with the label of the selected option, so it doesn’t need to execute a full search to find it. If an option is not valid, it should return `null`.

You can inject various utilities into these functions as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
Search | `?string` | `$search` | [`getOptionsSearchResultsUsing()` only] The current search input value, if the field is searchable. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
Option value | `mixed` | `$value` | The option value to retrieve the label for. 
 
### [#](#setting-a-custom-loading-message)Setting a custom loading message

When you’re using a searchable select or multi-select, you may want to display a custom message while the options are loading. You can do this using the `optionsLoadingMessage()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->optionsLoadingMessage('Loading authors...')

As well as allowing a static value, the `optionsLoadingMessage()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#setting-a-custom-no-search-results-message)Setting a custom no search results message

When you’re using a searchable select or multi-select, you may want to display a custom message when no search results are found. You can do this using the `noOptionsSearchResultsMessage()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->noOptionsSearchResultsMessage('No authors found.')

As well as allowing a static value, the `noOptionsSearchResultsMessage()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#setting-a-custom-search-prompt)Setting a custom search prompt

When you’re using a searchable select or multi-select, you may want to display a custom message when the user has not yet entered a search term. You can do this using the `optionsSearchPrompt()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions(['name', 'email'])
 ->optionsSearchPrompt('Search authors by their name or email address')

As well as allowing a static value, the `optionsSearchPrompt()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#setting-a-custom-searching-message)Setting a custom searching message

When you’re using a searchable select or multi-select, you may want to display a custom message while the search results are being loaded. You can do this using the `optionsSearchingMessage()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->optionsSearchingMessage('Searching authors...')

As well as allowing a static value, the `optionsSearchingMessage()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#tweaking-the-search-debounce)Tweaking the search debounce

By default, Filament will wait 1000 milliseconds (1 second) before searching for options when the user types in a searchable select or multi-select. It will also wait 1000 milliseconds between searches, if the user is continuously typing into the search input. You can change this using the `optionsSearchDebounce()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->optionsSearchDebounce(500)

Ensure that you are not lowering the debounce too much, as this may cause the select to become slow and unresponsive due to a high number of network requests to retrieve options from server.

As well as allowing a static value, the `optionsSearchDebounce()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#integrating-with-an-eloquent-relationship)Integrating with an Eloquent relationship

You may employ the `optionsRelationship()` method of the `SelectColumn` to configure a `BelongsTo` relationship to automatically retrieve options from. The `titleAttribute` is the name of a column that will be used to generate a label for each option:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')

### [#](#searching-relationship-options-across-multiple-columns)Searching relationship options across multiple columns

By default, if the select is also searchable, Filament will return search results for the relationship based on the title column of the relationship. If you’d like to search across multiple columns, you can pass an array of columns to the `searchableOptions()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions(['name', 'email'])

### [#](#preloading-relationship-options)Preloading relationship options

If you’d like to populate the searchable options from the database when the page is loaded, instead of when the user searches, you can use the `preloadOptions()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->preloadOptions()

Optionally, you may pass a boolean value to control if the input should be preloaded or not:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->preload(FeatureFlag::active())

As well as allowing a static value, the `preload()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#excluding-the-current-record)Excluding the current record

When working with recursive relationships, you will likely want to remove the current record from the set of results.

This can be easily be done using the `ignoreRecord` argument:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('parent_id')
 ->optionsRelationship(name: 'parent', titleAttribute: 'name', ignoreRecord: true)

### [#](#customizing-the-relationship-query)Customizing the relationship query

You may customize the database query that retrieves options using the third parameter of the `optionsRelationship()` method:

 use Filament\Tables\Columns\SelectColumn;
 use Illuminate\Database\Eloquent\Builder;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(
 name: 'author',
 titleAttribute: 'name',
 modifyQueryUsing: fn (Builder $query) => $query->withTrashed(),
 )

The `modifyQueryUsing` argument can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Query | `Illuminate\Database\Eloquent\Builder` | `$query` | The Eloquent query builder to modify. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
Search | `?string` | `$search` | The current search input value, if the field is searchable. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#customizing-the-relationship-option-labels)Customizing the relationship option labels

If you’d like to customize the label of each option, maybe to be more descriptive, or to concatenate a first and last name, you could use a virtual column in your database migration:

 $table->string('full_name')->virtualAs('concat(first_name, \' \', last_name)');

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'full_name')

Alternatively, you can use the `getOptionLabelFromRecordUsing()` method to transform an option’s Eloquent model into a label:

 use Filament\Tables\Columns\SelectColumn;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(
 name: 'author',
 modifyQueryUsing: fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name'),
 )
 ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->first_name} {$record->last_name}")
 ->searchableOptions(['first_name', 'last_name'])

The `getOptionLabelFromRecordUsing()` method can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record to get the option label for. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#remembering-options)Remembering options

By default, when using `optionsRelationship()`, Filament will remember the options for the duration of the table page to improve performance. This means that the options function will only run once per table page instead of once per cell. You can disable this behavior using the `rememberOptions(false)` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->rememberOptions(false)

NOTE

When options are remembered, any record-specific options or disabled options will not work correctly, as the same options will be used for all records in the table. If you need record-specific options or disabled options, you should disable option remembering.

As well as allowing a static value, the `rememberOptions()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#allowing-html-in-the-option-labels)Allowing HTML in the option labels

By default, Filament will escape any HTML in the option labels. If you’d like to allow HTML, you can use the `allowOptionsHtml()` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('technology')
 ->options([
 'tailwind' => '<span class="text-blue-500">Tailwind</span>',
 'alpine' => '<span class="text-green-500">Alpine</span>',
 'laravel' => '<span class="text-red-500">Laravel</span>',
 'livewire' => '<span class="text-pink-500">Livewire</span>',
 ])
 ->searchableOptions()
 ->allowOptionsHtml()

NOTE

Be aware that you will need to ensure that the HTML is safe to render, otherwise your application will be vulnerable to XSS attacks.

Optionally, you may pass a boolean value to control if the input should allow HTML or not:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('technology')
 ->options([
 'tailwind' => '<span class="text-blue-500">Tailwind</span>',
 'alpine' => '<span class="text-green-500">Alpine</span>',
 'laravel' => '<span class="text-red-500">Laravel</span>',
 'livewire' => '<span class="text-pink-500">Livewire</span>',
 ])
 ->searchableOptions()
 ->allowOptionsHtml(FeatureFlag::active())

As well as allowing a static value, the `allowOptionsHtml()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#wrap-or-truncate-option-labels)Wrap or truncate option labels

When using the JavaScript select, labels that exceed the width of the select element will wrap onto multiple lines by default. Alternatively, you may choose to truncate overflowing labels.

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('truncate')
 ->wrapOptionLabels(false)

As well as allowing a static value, the `wrapOptionLabels()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#disable-placeholder-selection)Disable placeholder selection

You can prevent the placeholder (null option) from being selected using the `selectablePlaceholder(false)` method:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->selectablePlaceholder(false)

As well as allowing a static value, the `selectablePlaceholder()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#disabling-specific-options)Disabling specific options

You can disable specific options using the `disableOptionWhen()` method. It accepts a closure, in which you can check if the option with a specific `$value` should be disabled:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->default('draft')
 ->disableOptionWhen(fn (string $value): bool => $value === 'published')

You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Option label | `string | Illuminate\Contracts\Support\Htmlable` | `$label` | The label of the option to disable. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
Option value | `mixed` | `$value` | The value of the option to disable. 
 
## [#](#limiting-the-number-of-options)Limiting the number of options

You can limit the number of options that are displayed in a searchable select or multi-select using the `optionsLimit()` method. The default is 50:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->optionsRelationship(name: 'author', titleAttribute: 'name')
 ->searchableOptions()
 ->optionsLimit(20)

Ensure that you are not raising the limit too high, as this may cause the select to become slow and unresponsive due to high in-browser memory usage.

As well as allowing a static value, the `optionsLimit()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#validation)Validation

You can validate the input by passing any [Laravel validation rules](https://laravel.com/docs/validation#available-validation-rules) in an array:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->rules(['required'])

### [#](#valid-options-validation-in-rule)Valid options validation (`in` rule)

The `in` rule ensures that users cannot select an option that is not in the list of options. This is an important rule for data integrity purposes, so Filament applies it by default to all select fields.

Since there are many ways for a select field to populate its options, and in many cases the options are not all loaded into the select by default and require searching to retrieve them, Filament uses the presence of a valid “option label” to determine whether the selected value exists. It also checks if that option is [disabled](#disabling-specific-options) or not.

If you are using a custom search query to retrieve options, you should ensure that the `getOptionLabelUsing()` method is defined, so that Filament can validate the selected value against the available options:

 use Filament\Tables\Columns\SelectColumn;
 
 SelectColumn::make('author_id')
 ->searchableOptions()
 ->getOptionsSearchResultsUsing(fn (string $search): array => Author::query()
 ->where('name', 'like', "%{$search}%")
 ->limit(50)
 ->pluck('name', 'id')
 ->all())
 ->getOptionLabelUsing(fn (string $value): ?string => Author::find($value)?->name),

The `getOptionLabelUsing()` method should return `null` if the option is not valid, to allow Filament to determine that the selected value is not in the list of options. If the option is valid, it should return the label of the option.

If you are using the `optionsRelationship()` method, the `getOptionLabelUsing()` method will be automatically defined for you, so you don’t need to worry about it.

## [#](#lifecycle-hooks)Lifecycle hooks

Hooks may be used to execute code at various points within the select’s lifecycle:

 SelectColumn::make()
 ->beforeStateUpdated(function ($record, $state) {
 // Runs before the state is saved to the database.
 })
 ->afterStateUpdated(function ($record, $state) {
 // Runs after the state is saved to the database.
 })

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/06-select.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)