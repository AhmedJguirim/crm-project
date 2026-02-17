# Source: https://filamentphp.com/docs/4.x/tables/empty-state

Tables 

# Empty state 

## [#](#introduction)Introduction

The table’s “empty state” is rendered when there are no rows in the table.

![Table with empty state](/docs/4.x/images/light/tables/empty-state.jpg) ![Table with empty state](/docs/4.x/images/dark/tables/empty-state.jpg)

## [#](#setting-the-empty-state-heading)Setting the empty state heading

To customize the heading of the empty state, use the `emptyStateHeading()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->emptyStateHeading('No posts yet');
 }

![Table with customized empty state heading](/docs/4.x/images/light/tables/empty-state-heading.jpg) ![Table with customized empty state heading](/docs/4.x/images/dark/tables/empty-state-heading.jpg)

## [#](#setting-the-empty-state-description)Setting the empty state description

To customize the description of the empty state, use the `emptyStateDescription()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->emptyStateDescription('Once you write your first post, it will appear here.');
 }

![Table with empty state description](/docs/4.x/images/light/tables/empty-state-description.jpg) ![Table with empty state description](/docs/4.x/images/dark/tables/empty-state-description.jpg)

## [#](#setting-the-empty-state-icon)Setting the empty state icon

To customize the [icon](../styling/icons) of the empty state, use the `emptyStateIcon()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->emptyStateIcon('heroicon-o-bookmark');
 }

![Table with customized empty state icon](/docs/4.x/images/light/tables/empty-state-icon.jpg) ![Table with customized empty state icon](/docs/4.x/images/dark/tables/empty-state-icon.jpg)

## [#](#adding-empty-state-actions)Adding empty state actions

You can add [Actions](actions) to the empty state to prompt users to take action. Pass these to the `emptyStateActions()` method:

 use Filament\Actions\Action;
 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->emptyStateActions([
 Action::make('create')
 ->label('Create post')
 ->url(route('posts.create'))
 ->icon('heroicon-m-plus')
 ->button(),
 ]);
 }

![Table with empty state actions](/docs/4.x/images/light/tables/empty-state-actions.jpg) ![Table with empty state actions](/docs/4.x/images/dark/tables/empty-state-actions.jpg)

## [#](#using-a-custom-empty-state-view)Using a custom empty state view

You may use a completely custom empty state view by passing it to the `emptyState()` method:

 use Filament\Tables\Table;
 
 public function table(Table $table): Table
 {
 return $table
 ->emptyState(view('tables.posts.empty-state'));
 }

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/08-empty-state.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)