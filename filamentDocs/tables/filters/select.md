# Source: https://filamentphp.com/docs/4.x/tables/filters/select

Tables \- Filters 

# Select filters 

## [#](#introduction)Introduction

Often, you will want to use a [select field](../../forms/select) instead of a checkbox. This is especially true when you want to filter a column based on a set of pre-defined options that the user can choose from. To do this, you can create a filter using the `SelectFilter` class:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])

The `options()` that are passed to the filter are the same as those that are passed to the [select field](../../forms/select).

As well as allowing a static value, the `options()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/filters/overview#filter-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Filter | `Filament\Tables\Filters\BaseFilter` | `$filter` | The current filter instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#customizing-the-column-used-by-a-select-filter)Customizing the column used by a select filter

Select filters do not require a custom `query()` method. The column name used to scope the query is the name of the filter. To customize this, you may use the `attribute()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->attribute('status_id')

As well as allowing a static value, the `attribute()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/filters/overview#filter-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Filter | `Filament\Tables\Filters\BaseFilter` | `$filter` | The current filter instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#multi-select-filters)Multi-select filters

These allow the user to [select multiple options](../../forms/select#multi-select) to apply the filter to their table. For example, a status filter may present the user with a few status options to pick from and filter the table using. When the user selects multiple options, the table will be filtered to show records that match any of the selected options. You can enable this behavior using the `multiple()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('status')
 ->multiple()
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])

## [#](#relationship-select-filters)Relationship select filters

Select filters are also able to automatically populate themselves based on a relationship. For example, if your table has a `author` relationship with a `name` column, you may use `relationship()` to filter the records belonging to an author:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('author')
 ->relationship('author', 'name')

### [#](#preloading-the-select-filter-relationship-options)Preloading the select filter relationship options

If you’d like to populate the searchable options from the database when the page is loaded, instead of when the user searches, you can use the `preload()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('author')
 ->relationship('author', 'name')
 ->searchable()
 ->preload()

### [#](#filtering-empty-relationships)Filtering empty relationships

By default, upon selecting an option, all records that have an empty relationship will be excluded from the results. If you want to introduce an additional “None” option for the user to select, which will include all records that do not have a relationship, you can use the `hasEmptyOption()` argument of the `relationship()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('author')
 ->relationship('author', 'name', hasEmptyOption: true)

You can rename the “None” option using the `emptyRelationshipOptionLabel()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('author')
 ->relationship('author', 'name', hasEmptyOption: true)
 ->emptyRelationshipOptionLabel('No author')

### [#](#customizing-the-select-filter-relationship-query)Customizing the select filter relationship query

You may customize the database query that retrieves options using the third parameter of the `relationship()` method:

 use Filament\Tables\Filters\SelectFilter;
 use Illuminate\Database\Eloquent\Builder;
 
 SelectFilter::make('author')
 ->relationship('author', 'name', fn (Builder $query) => $query->withTrashed())

### [#](#searching-select-filter-options)Searching select filter options

You may enable a search input to allow easier access to many options, using the `searchable()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('author')
 ->relationship('author', 'name')
 ->searchable()

## [#](#disable-placeholder-selection)Disable placeholder selection

You can remove the placeholder (null option), which disables the filter so all options are applied, using the `selectablePlaceholder()` method:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->default('draft')
 ->selectablePlaceholder(false)

## [#](#applying-select-filters-by-default)Applying select filters by default

You may set a select filter to be enabled by default, using the `default()` method. If using a single select filter, the `default()` method accepts a single option value. If using a `multiple()` select filter, the `default()` method accepts an array of option values:

 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->default('draft')
 
 SelectFilter::make('status')
 ->options([
 'draft' => 'Draft',
 'reviewing' => 'Reviewing',
 'published' => 'Published',
 ])
 ->multiple()
 ->default(['draft', 'reviewing'])

As well as allowing a static value, the `default()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/filters/overview#filter-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Filter | `Filament\Tables\Filters\BaseFilter` | `$filter` | The current filter instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/03-filters/02-select.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)