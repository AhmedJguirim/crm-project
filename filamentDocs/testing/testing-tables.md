# Source: https://filamentphp.com/docs/4.x/testing/testing-tables

Testing 

# Testing tables 

## [#](#testing-that-a-table-can-render)Testing that a table can render

To ensure a table component renders, use the `assertSuccessful()` Livewire helper:

 use function Pest\Livewire\livewire;
 
 it('can render page', function () {
 livewire(ListPosts::class)
 ->assertSuccessful();
 });

To test which records are shown, you can use `assertCanSeeTableRecords()`, `assertCanNotSeeTableRecords()` and `assertCountTableRecords()`:

 use function Pest\Livewire\livewire;
 
 it('cannot display trashed posts by default', function () {
 $posts = Post::factory()->count(4)->create();
 $trashedPosts = Post::factory()->trashed()->count(6)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanSeeTableRecords($posts)
 ->assertCanNotSeeTableRecords($trashedPosts)
 ->assertCountTableRecords(4);
 });

> If your table uses pagination, `assertCanSeeTableRecords()` will only check for records on the first page. To switch page, call `call('gotoPage', 2)`.

> If your table uses `deferLoading()`, you should call `loadTable()` before `assertCanSeeTableRecords()`.

## [#](#testing-columns)Testing columns

To ensure that a certain column is rendered, pass the column name to `assertCanRenderTableColumn()`:

 use function Pest\Livewire\livewire;
 
 it('can render post titles', function () {
 Post::factory()->count(10)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanRenderTableColumn('title');
 });

This helper will get the HTML for this column, and check that it is present in the table.

For testing that a column is not rendered, you can use `assertCanNotRenderTableColumn()`:

 use function Pest\Livewire\livewire;
 
 it('can not render post comments', function () {
 Post::factory()->count(10)->create()
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanNotRenderTableColumn('comments');
 });

This helper will assert that the HTML for this column is not shown by default in the present table.

### [#](#testing-that-a-column-can-be-searched)Testing that a column can be searched

To search the table, call the `searchTable()` method with your search query.

You can then use `assertCanSeeTableRecords()` to check your filtered table records, and use `assertCanNotSeeTableRecords()` to assert that some records are no longer in the table:

 use function Pest\Livewire\livewire;
 
 it('can search posts by title', function () {
 $posts = Post::factory()->count(10)->create();
 
 $title = $posts->first()->title;
 
 livewire(PostResource\Pages\ListPosts::class)
 ->searchTable($title)
 ->assertCanSeeTableRecords($posts->where('title', $title))
 ->assertCanNotSeeTableRecords($posts->where('title', '!=', $title));
 });

To search individual columns, you can pass an array of searches to `searchTableColumns()`:

 use function Pest\Livewire\livewire;
 
 it('can search posts by title column', function () {
 $posts = Post::factory()->count(10)->create();
 
 $title = $posts->first()->title;
 
 livewire(PostResource\Pages\ListPosts::class)
 ->searchTableColumns(['title' => $title])
 ->assertCanSeeTableRecords($posts->where('title', $title))
 ->assertCanNotSeeTableRecords($posts->where('title', '!=', $title));
 });

### [#](#testing-that-a-column-can-be-sorted)Testing that a column can be sorted

To sort table records, you can call `sortTable()`, passing the name of the column to sort by. You can use `'desc'` in the second parameter of `sortTable()` to reverse the sorting direction.

Once the table is sorted, you can ensure that the table records are rendered in order using `assertCanSeeTableRecords()` with the `inOrder` parameter:

 use function Pest\Livewire\livewire;
 
 it('can sort posts by title', function () {
 Post::factory()->count(10)->create();
 
 $sortedPostsAsc = Post::query()->orderBy('title')->get();
 $sortedPostsDesc = Post::query()->orderBy('title', 'desc')->get();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->sortTable('title')
 ->assertCanSeeTableRecords($sortedPostsAsc, inOrder: true)
 ->sortTable('title', 'desc')
 ->assertCanSeeTableRecords($sortedPostsDesc, inOrder: true);
 });

NOTE

Filament tables use a SQL `order` statement to sort records before they are output. Different database drivers can use different sorting strategies, and they can differ from PHP’s own sorting strategy, so you should ensure that test records are sorted using `orderBy()` on a database query rather than `sortBy()` on a collection of models.

### [#](#testing-the-state-of-a-column)Testing the state of a column

To assert that a certain column has a state or does not have a state for a record you can use `assertTableColumnStateSet()` and `assertTableColumnStateNotSet()`:

 use function Pest\Livewire\livewire;
 
 it('can get post author names', function () {
 $posts = Post::factory()->count(10)->create();
 
 $post = $posts->first();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableColumnStateSet('author.name', $post->author->name, record: $post)
 ->assertTableColumnStateNotSet('author.name', 'Anonymous', record: $post);
 });

To assert that a certain column has a formatted state or does not have a formatted state for a record you can use `assertTableColumnFormattedStateSet()` and `assertTableColumnFormattedStateNotSet()`:

 use function Pest\Livewire\livewire;
 
 it('can get post author names', function () {
 $post = Post::factory(['name' => 'John Smith'])->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableColumnFormattedStateSet('author.name', 'Smith, John', record: $post)
 ->assertTableColumnFormattedStateNotSet('author.name', $post->author->name, record: $post);
 });

### [#](#testing-the-existence-of-a-column)Testing the existence of a column

To ensure that a column exists, you can use the `assertTableColumnExists()` method:

 use function Pest\Livewire\livewire;
 
 it('has an author column', function () {
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableColumnExists('author');
 });

You may pass a function as an additional argument to assert that a column passes a given “truth test”. This is useful for asserting that a column has a specific configuration. You can also pass in a record as the third parameter, which is useful if your check is dependent on which table row is being rendered:

 use function Pest\Livewire\livewire;
 use Filament\Tables\Columns\TextColumn;
 
 it('has an author column', function () {
 $post = Post::factory()->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableColumnExists('author', function (TextColumn $column): bool {
 return $column->getDescriptionBelow() === $post->subtitle;
 }, $post);
 });

### [#](#testing-the-visibility-of-a-column)Testing the visibility of a column

To ensure that a particular user cannot see a column, you can use the `assertTableColumnVisible()` and `assertTableColumnHidden()` methods:

 use function Pest\Livewire\livewire;
 
 it('shows the correct columns', function () {
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableColumnVisible('created_at')
 ->assertTableColumnHidden('author');
 });

### [#](#testing-the-description-of-a-column)Testing the description of a column

To ensure a column has the correct description above or below you can use the `assertTableColumnHasDescription()` and `assertTableColumnDoesNotHaveDescription()` methods:

 use function Pest\Livewire\livewire;
 
 it('has the correct descriptions above and below author', function () {
 $post = Post::factory()->create();
 
 livewire(PostsTable::class)
 ->assertTableColumnHasDescription('author', 'Author! ↓↓↓', $post, 'above')
 ->assertTableColumnHasDescription('author', 'Author! ↑↑↑', $post)
 ->assertTableColumnDoesNotHaveDescription('author', 'Author! ↑↑↑', $post, 'above')
 ->assertTableColumnDoesNotHaveDescription('author', 'Author! ↓↓↓', $post);
 });

### [#](#testing-the-extra-attributes-of-a-column)Testing the extra attributes of a column

To ensure that a column has the correct extra attributes, you can use the `assertTableColumnHasExtraAttributes()` and `assertTableColumnDoesNotHaveExtraAttributes()` methods:

 use function Pest\Livewire\livewire;
 
 it('displays author in red', function () {
 $post = Post::factory()->create();
 
 livewire(PostsTable::class)
 ->assertTableColumnHasExtraAttributes('author', ['class' => 'text-danger-500'], $post)
 ->assertTableColumnDoesNotHaveExtraAttributes('author', ['class' => 'text-primary-500'], $post);
 });

### [#](#testing-the-options-in-a-selectcolumn)Testing the options in a `SelectColumn`

If you have a select column, you can ensure it has the correct options with `assertTableSelectColumnHasOptions()` and `assertTableSelectColumnDoesNotHaveOptions()`:

 use function Pest\Livewire\livewire;
 
 it('has the correct statuses', function () {
 $post = Post::factory()->create();
 
 livewire(PostsTable::class)
 ->assertTableSelectColumnHasOptions('status', ['unpublished' => 'Unpublished', 'published' => 'Published'], $post)
 ->assertTableSelectColumnDoesNotHaveOptions('status', ['archived' => 'Archived'], $post);
 });

## [#](#testing-filters)Testing filters

To filter the table records, you can use the `filterTable()` method, along with `assertCanSeeTableRecords()` and `assertCanNotSeeTableRecords()`:

 use function Pest\Livewire\livewire;
 
 it('can filter posts by `is_published`', function () {
 $posts = Post::factory()->count(10)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanSeeTableRecords($posts)
 ->filterTable('is_published')
 ->assertCanSeeTableRecords($posts->where('is_published', true))
 ->assertCanNotSeeTableRecords($posts->where('is_published', false));
 });

For a simple filter, this will just enable the filter.

If you’d like to set the value of a `SelectFilter` or `TernaryFilter`, pass the value as a second argument:

 use function Pest\Livewire\livewire;
 
 it('can filter posts by `author_id`', function () {
 $posts = Post::factory()->count(10)->create();
 
 $authorId = $posts->first()->author_id;
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanSeeTableRecords($posts)
 ->filterTable('author_id', $authorId)
 ->assertCanSeeTableRecords($posts->where('author_id', $authorId))
 ->assertCanNotSeeTableRecords($posts->where('author_id', '!=', $authorId));
 });

### [#](#resetting-filters-in-a-test)Resetting filters in a test

To reset all filters to their original state, call `resetTableFilters()`:

 use function Pest\Livewire\livewire;
 
 it('can reset table filters', function () {
 $posts = Post::factory()->count(10)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->resetTableFilters();
 });

### [#](#removing-filters-in-a-test)Removing filters in a test

To remove a single filter you can use `removeTableFilter()`:

 use function Pest\Livewire\livewire;
 
 it('filters list by published', function () {
 $posts = Post::factory()->count(10)->create();
 
 $unpublishedPosts = $posts->where('is_published', false)->get();
 
 livewire(PostsTable::class)
 ->filterTable('is_published')
 ->assertCanNotSeeTableRecords($unpublishedPosts)
 ->removeTableFilter('is_published')
 ->assertCanSeeTableRecords($posts);
 });

To remove all filters you can use `removeTableFilters()`:

 use function Pest\Livewire\livewire;
 
 it('can remove all table filters', function () {
 $posts = Post::factory()->count(10)->forAuthor()->create();
 
 $unpublishedPosts = $posts
 ->where('is_published', false)
 ->where('author_id', $posts->first()->author->getKey());
 
 livewire(PostsTable::class)
 ->filterTable('is_published')
 ->filterTable('author', $author)
 ->assertCanNotSeeTableRecords($unpublishedPosts)
 ->removeTableFilters()
 ->assertCanSeeTableRecords($posts);
 });

### [#](#testing-the-visibility-of-a-filter)Testing the visibility of a filter

To ensure that a particular user cannot see a filter, you can use the `assertTableFilterVisible()` and `assertTableFilterHidden()` methods:

 use function Pest\Livewire\livewire;
 
 it('shows the correct filters', function () {
 livewire(PostsTable::class)
 ->assertTableFilterVisible('created_at')
 ->assertTableFilterHidden('author');
 });

### [#](#testing-the-existence-of-a-filter)Testing the existence of a filter

To ensure that a filter exists, you can use the `assertTableFilterExists()` method:

 use function Pest\Livewire\livewire;
 
 it('has an author filter', function () {
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableFilterExists('author');
 });

You may pass a function as an additional argument to assert that a filter passes a given “truth test”. This is useful for asserting that a filter has a specific configuration:

 use function Pest\Livewire\livewire;
 use Filament\Tables\Filters\SelectFilter;
 
 it('has an author filter', function () {
 livewire(PostResource\Pages\ListPosts::class)
 ->assertTableFilterExists('author', function (SelectFilter $column): bool {
 return $column->getLabel() === 'Select author';
 });
 });

## [#](#testing-summaries)Testing summaries

To test that a summary calculation is working, you may use the `assertTableColumnSummarySet()` method:

 use function Pest\Livewire\livewire;
 
 it('can average values in a column', function () {
 $posts = Post::factory()->count(10)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanSeeTableRecords($posts)
 ->assertTableColumnSummarySet('rating', 'average', $posts->avg('rating'));
 });

The first argument is the column name, the second is the summarizer ID, and the third is the expected value.

Note that the expected and actual values are normalized, such that `123.12` is considered the same as `"123.12"`, and `['Fred', 'Jim']` is the same as `['Jim', 'Fred']`.

You may set a summarizer ID by passing it to the `make()` method:

 use Filament\Tables\Columns\Summarizers\Average;
 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('rating')
 ->summarize(Average::make('average'))

The ID should be unique between summarizers in that column.

### [#](#testing-summaries-on-only-one-pagination-page)Testing summaries on only one pagination page

To calculate the average for only one pagination page, use the `isCurrentPaginationPageOnly` argument:

 use function Pest\Livewire\livewire;
 
 it('can average values in a column', function () {
 $posts = Post::factory()->count(20)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanSeeTableRecords($posts->take(10))
 ->assertTableColumnSummarySet('rating', 'average', $posts->take(10)->avg('rating'), isCurrentPaginationPageOnly: true);
 });

### [#](#testing-a-range-summarizer)Testing a range summarizer

To test a range, pass the minimum and maximum value into a tuple-style `[$minimum, $maximum]` array:

 use function Pest\Livewire\livewire;
 
 it('can average values in a column', function () {
 $posts = Post::factory()->count(10)->create();
 
 livewire(PostResource\Pages\ListPosts::class)
 ->assertCanSeeTableRecords($posts)
 ->assertTableColumnSummarySet('rating', 'range', [$posts->min('rating'), $posts->max('rating')]);
 });

## [#](#testing-toggleable-columns)Testing toggleable columns

By default, only columns that are toggled on by default in the table will be rendered and testable. You can toggle all columns in the table on using `toggleAllTableColumns()`:

 use function Pest\Livewire\livewire;
 
 it('can toggle all columns', function () {
 livewire(PostResource\Pages\ListPosts::class)
 ->toggleAllTableColumns();
 });

You can also toggle all columns off using `toggleAllTableColumns(false)`:

 use function Pest\Livewire\livewire;
 
 it('can toggle all columns off', function () {
 livewire(PostResource\Pages\ListPosts::class)
 ->toggleAllTableColumns(false);
 });

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/docs/10-testing/03-testing-tables.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)