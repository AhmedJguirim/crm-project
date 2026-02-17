# Source: https://filamentphp.com/docs/4.x/tables/filters/layout

Tables \- Filters 

# Filter layout 

## [#](#positioning-filters-into-grid-columns)Positioning filters into grid columns

To change the number of columns that filters may occupy, you may use the `filtersFormColumns()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->filtersFormColumns(3);
 }

## [#](#controlling-the-width-of-the-filters-dropdown)Controlling the width of the filters dropdown

To customize the dropdown width, you may use the `filtersFormWidth()` method, and specify a width - `ExtraSmall`, `Small`, `Medium`, `Large`, `ExtraLarge`, `TwoExtraLarge`, `ThreeExtraLarge`, `FourExtraLarge`, `FiveExtraLarge`, `SixExtraLarge` or `SevenExtraLarge`. By default, the width is `ExtraSmall`:

 use Filament\Support\Enums\Width;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->filtersFormWidth(Width::FourExtraLarge);
 }

## [#](#controlling-the-maximum-height-of-the-filters-dropdown)Controlling the maximum height of the filters dropdown

To add a maximum height to the filters’ dropdown content, so that they scroll, you may use the `filtersFormMaxHeight()` method, passing a [CSS length](https://developer.mozilla.org/en-US/docs/Web/CSS/length):

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->filtersFormMaxHeight('400px');
 }

## [#](#displaying-filters-in-a-modal)Displaying filters in a modal

To render the filters in a modal instead of in a dropdown, you may use:

 use Filament\Tables\Enums\FiltersLayout;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ], layout: FiltersLayout::Modal);
 }

You may use the [trigger action API](overview#customizing-the-filters-trigger-action) to [customize the modal](../../actions/modals), including [using a `slideOver()`](../../actions/modals#using-a-slide-over-instead-of-a-modal).

## [#](#displaying-filters-above-the-table-content)Displaying filters above the table content

To render the filters above the table content instead of in a dropdown, you may use:

 use Filament\Tables\Enums\FiltersLayout;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ], layout: FiltersLayout::AboveContent);
 }

![Table with filters above content](/docs/4.x/images/light/tables/filters/above-content.jpg) ![Table with filters above content](/docs/4.x/images/dark/tables/filters/above-content.jpg)

### [#](#allowing-filters-above-the-table-content-to-be-collapsed)Allowing filters above the table content to be collapsed

To allow the filters above the table content to be collapsed, you may use:

 use Filament\Tables\Enums\FiltersLayout;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ], layout: FiltersLayout::AboveContentCollapsible);
 }

## [#](#displaying-filters-below-the-table-content)Displaying filters below the table content

To render the filters below the table content instead of in a dropdown, you may use:

 use Filament\Tables\Enums\FiltersLayout;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ], layout: FiltersLayout::BelowContent);
 }

![Table with filters below content](/docs/4.x/images/light/tables/filters/below-content.jpg) ![Table with filters below content](/docs/4.x/images/dark/tables/filters/below-content.jpg)

## [#](#displaying-filters-to-the-left-or-right-of-the-table-content)Displaying filters to the left or right of the table content

To render the filters to the left (before) or right (after) of the table content instead of in a dropdown, you may use:

 use Filament\Tables\Enums\FiltersLayout;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ], layout: FiltersLayout::BeforeContent); // or `FiltersLayout::AfterContent`
 }

### [#](#allowing-filters-to-be-collapsible-when-displayed-to-the-left-or-right-of-the-table-content)Allowing filters to be collapsible when displayed to the left or right of the table content

To allow the filters to be collapsible when displayed to the left or right of the table content, you may use:

 use Filament\Tables\Enums\FiltersLayout;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ], layout: FiltersLayout::BeforeContentCollapsible); // or `FiltersLayout::AfterContentCollapsible`
 }

## [#](#hiding-the-filter-indicators)Hiding the filter indicators

To hide the active filters indicators above the table, you may use `hiddenFilterIndicators()`:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->hiddenFilterIndicators();
 }

## [#](#customizing-the-filter-form-schema)Customizing the filter form schema

You may customize the [form schema](../../schemas/layouts) of the entire filter form at once, in order to rearrange filters into your desired layout, and use any of the [layout components](../../schemas/layouts) available to forms. To do this, use the `filterFormSchema()` method, passing a closure function that receives the array of defined `$filters` that you can insert:

 use Filament\Schemas\Components\Section;
 use Filament\Tables\Filters\Filter;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 Filter::make('is_featured'),
 Filter::make('published_at'),
 Filter::make('author'),
 ])
 ->filtersFormColumns(2)
 ->filtersFormSchema(fn (array $filters): array => [
 Section::make('Visibility')
 ->description('These filters affect the visibility of the records in the table.')
 ->schema([
 $filters['is_featured'],
 $filters['published_at'],
 ])
 ->columns(2)
 ->columnSpanFull(),
 $filters['author'],
 ]);
 }

In this example, we have put two of the filters inside a [section](../../schemas/sections) component, and used the `columns()` method to specify that the section should have two columns. We have also used the `columnSpanFull()` method to specify that the section should span the full width of the filter form, which is also 2 columns wide. We have inserted each filter into the form schema by using the filter’s name as the key in the `$filters` array.

## [#](#displaying-the-reset-action-in-the-footer)Displaying the reset action in the footer

By default, the reset action appears in the header of the filters form. You may move it to the footer, next to the apply action, using the `filtersResetActionPosition()` method:

 use Filament\Tables\Enums\FiltersResetActionPosition;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->filters([
 // ...
 ])
 ->filtersResetActionPosition(FiltersResetActionPosition::Footer);
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/03-filters/06-layout.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)