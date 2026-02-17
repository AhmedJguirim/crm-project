# Source: https://filamentphp.com/docs/4.x/tables/filters/overview

Tables \- Filters 

# Overview 

## [#](#introduction)Introduction

Filters allow you to define certain constraints on your data, and allow users to scope it to find the information they need. You put them in the `$table->filters()` method.

Filters may be created using the static `make()` method, passing its unique name. You should then pass a callback to `query()` which applies your filter’s scope:

 use Filament\Tables\Filters\Filter;
 use Filament\Tables\Table;
 use Illuminate\Database\Eloquent\Builder;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 Filter::make('is_featured')
 ->query(fn (Builder $query): Builder => $query->where('is_featured', true))
 // ...
 ]);
 }

![Table with filter](/docs/4.x/images/light/tables/filters/simple.jpg) ![Table with filter](/docs/4.x/images/dark/tables/filters/simple.jpg)

## [#](#available-filters)Available filters

By default, using the `Filter::make()` method will render a checkbox form component. When the checkbox is on, the `query()` will be activated.

 * You can also [replace the checkbox with a toggle](#using-a-toggle-button-instead-of-a-checkbox).
 * You may use a [select filter](select) to allow users to select from a list of options, and filter using the selection.
 * You can use a [ternary filter](ternary) to replace the checkbox with a select field to allow users to pick between 3 states - usually “true”, “false” and “blank”. This is useful for filtering boolean columns.
 * The [trashed filter](ternary#filtering-soft-deletable-records) is a pre-built ternary filter that allows you to filter soft-deletable records.
 * Using a [query builder](query-builder), users can create complex sets of filters, with an advanced user interface for combining constraints.
 * You may build [custom filters](custom) with other form fields, to do whatever you want.

## [#](#setting-a-label)Setting a label

By default, the label of the filter is generated from the name of the filter. You may customize this using the `label()` method:

 use Filament\Tables\Filters\Filter;
 
 Filter::make('is_featured')
 ->label('Featured')

As well as allowing a static value, the `label()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/filters/overview#filter-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Filter | `Filament\Tables\Filters\BaseFilter` | `$filter` | The current filter instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
Customizing the label in this way is useful if you wish to use a [translation string for localization](https://laravel.com/docs/localization#retrieving-translation-strings):

 use Filament\Tables\Filters\Filter;
 
 Filter::make('is_featured')
 ->label(__('filters.is_featured'))

## [#](#customizing-the-filter-schema)Customizing the filter schema

By default, creating a filter with the `Filter` class will render a [checkbox form component](../../forms/checkbox). When the checkbox is checked, the `query()` function will be applied to the table’s query, scoping the records in the table. When the checkbox is unchecked, the `query()` function will be removed from the table’s query.

Filters are built entirely on Filament’s form fields. They can render any combination of form fields, which users can then interact with to filter the table.

### [#](#using-a-toggle-button-instead-of-a-checkbox)Using a toggle button instead of a checkbox

The simplest example of managing the form field that is used for a filter is to replace the [checkbox](../../forms/checkbox) with a [toggle button](../../forms/toggle), using the `toggle()` method:

 use Filament\Tables\Filters\Filter;
 
 Filter::make('is_featured')
 ->toggle()

![Table with toggle filter](/docs/4.x/images/light/tables/filters/toggle.jpg) ![Table with toggle filter](/docs/4.x/images/dark/tables/filters/toggle.jpg)

### [#](#customizing-the-built-in-filter-form-field)Customizing the built-in filter form field

Whether you are using a checkbox, a [toggle](#using-a-toggle-button-instead-of-a-checkbox) or a [select](select), you can customize the built-in form field used for the filter, using the `modifyFormFieldUsing()` method. The method accepts a function with a `$field` parameter that gives you access to the form field object to customize:

 use Filament\Forms\Components\Checkbox;
 use Filament\Tables\Filters\Filter;
 
 Filter::make('is_featured')
 ->modifyFormFieldUsing(fn (Checkbox $field) => $field->inline(false))

The function passed to `modifyFormFieldUsing()` can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/filters/overview#filter-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$field` | The field object to modify. 
Filter | `Filament\Tables\Filters\BaseFilter` | `$filter` | The current filter instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#applying-the-filter-by-default)Applying the filter by default

You may set a filter to be enabled by default, using the `default()` method:

 use Filament\Tables\Filters\Filter;
 
 Filter::make('is_featured')
 ->default()

If you’re using a [select filter](select), [visit the “applying select filters by default” section](select#applying-select-filters-by-default).

## [#](#persisting-filters-in-the-users-session)Persisting filters in the user’s session

To persist the table filters in the user’s session, use the `persistFiltersInSession()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->persistFiltersInSession();
 }

## [#](#live-filters)Live filters

By default, filter changes are deferred and do not affect the table, until the user clicks an “Apply” button. To disable this and make the filters “live” instead, use the `deferFilters(false)` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->deferFilters(false);
 }

### [#](#customizing-the-apply-filters-action)Customizing the apply filters action

When deferring filters, you can customize the “Apply” button, using the `filtersApplyAction()` method, passing a closure that returns an action. All methods that are available to [customize action trigger buttons](../../actions/overview) can be used:

 use Filament\Actions\Action;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->filtersApplyAction(
 fn (Action $action) => $action
 ->link()
 ->label('Save filters to table'),
 );
 }

## [#](#deselecting-records-when-filters-change)Deselecting records when filters change

By default, all records will be deselected when the filters change. Using the `deselectAllRecordsWhenFiltered(false)` method, you can disable this behavior:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->deselectAllRecordsWhenFiltered(false);
 }

## [#](#modifying-the-base-query)Modifying the base query

By default, modifications to the Eloquent query performed in the `query()` method will be applied inside a scoped `where()` clause. This is to ensure that the query does not clash with any other filters that may be applied, especially those that use `orWhere()`.

However, the downside of this is that the `query()` method cannot be used to modify the query in other ways, such as removing global scopes, since the base query needs to be modified directly, not the scoped query.

To modify the base query directly, you may use the `baseQuery()` method, passing a closure that receives the base query:

 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\SoftDeletingScope;
 use Filament\Tables\Filters\TernaryFilter;
 
 TernaryFilter::make('trashed')
 // ...
 ->baseQuery(fn (Builder $query) => $query->withoutGlobalScopes([
 SoftDeletingScope::class,
 ]))

## [#](#customizing-the-filters-trigger-action)Customizing the filters trigger action

To customize the filters trigger buttons, you may use the `filtersTriggerAction()` method, passing a closure that returns an action. All methods that are available to [customize action trigger buttons](../../actions/overview) can be used:

 use Filament\Actions\Action;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->filtersTriggerAction(
 fn (Action $action) => $action
 ->button()
 ->label('Filter'),
 );
 }

![Table with custom filters trigger action](/docs/4.x/images/light/tables/filters/custom-trigger-action.jpg) ![Table with custom filters trigger action](/docs/4.x/images/dark/tables/filters/custom-trigger-action.jpg)

## [#](#filter-utility-injection)Filter utility injection

The vast majority of methods used to configure filters accept functions as parameters instead of hardcoded values:

 use App\Models\Author;
 use Filament\Tables\Filters\SelectFilter;
 
 SelectFilter::make('author')
 ->options(fn (): array => Author::query()->pluck('name', 'id')->all())

This alone unlocks many customization possibilities.

The package is also able to inject many utilities to use inside these functions, as parameters. All customization methods that accept functions as arguments can inject utilities.

These injected utilities require specific parameter names to be used. Otherwise, Filament doesn’t know what to inject.

### [#](#injecting-the-current-filter-instance)Injecting the current filter instance

If you wish to access the current filter instance, define a `$filter` parameter:

 use Filament\Tables\Filters\BaseFilter;
 
 function (BaseFilter $filter) {
 // ...
 }

### [#](#injecting-the-current-livewire-component-instance)Injecting the current Livewire component instance

If you wish to access the current Livewire component instance that the table belongs to, define a `$livewire` parameter:

 use Filament\Tables\Contracts\HasTable;
 
 function (HasTable $livewire) {
 // ...
 }

### [#](#injecting-the-current-table-instance)Injecting the current table instance

If you wish to access the current table configuration instance that the filter belongs to, define a `$table` parameter:

 use Filament\Tables\Table;
 
 function (Table $table) {
 // ...
 }

### [#](#injecting-multiple-utilities)Injecting multiple utilities

The parameters are injected dynamically using reflection, so you are able to combine multiple parameters in any order:

 use Filament\Tables\Contracts\HasTable;
 use Filament\Tables\Table;
 
 function (HasTable $livewire, Table $table) {
 // ...
 }

### [#](#injecting-dependencies-from-laravels-container)Injecting dependencies from Laravel’s container

You may inject anything from Laravel’s container like normal, alongside utilities:

 use Filament\Tables\Table;
 use Illuminate\Http\Request;
 
 function (Request $request, Table $table) {
 // ...
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/03-filters/01-overview.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)