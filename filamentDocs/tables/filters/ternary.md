# Source: https://filamentphp.com/docs/4.x/tables/filters/ternary

Tables \- Filters 

# Ternary filters 

## [#](#introduction)Introduction

Ternary filters allow you to easily create a select filter which has three states - usually true, false and blank. To filter a column named `is_admin` to be `true` or `false`, you may use the ternary filter:

 use Filament\Tables\Filters\TernaryFilter;
 
 TernaryFilter::make('is_admin')

## [#](#using-a-ternary-filter-with-a-nullable-column)Using a ternary filter with a nullable column

Another common pattern is to use a nullable column. For example, when filtering verified and unverified users using the `email_verified_at` column, unverified users have a null timestamp in this column. To apply that logic, you may use the `nullable()` method:

 use Filament\Tables\Filters\TernaryFilter;
 
 TernaryFilter::make('email_verified_at')
 ->nullable()

## [#](#customizing-the-column-used-by-a-ternary-filter)Customizing the column used by a ternary filter

The column name used to scope the query is the name of the filter. To customize this, you may use the `attribute()` method:

 use Filament\Tables\Filters\TernaryFilter;
 
 TernaryFilter::make('verified')
 ->nullable()
 ->attribute('status_id')

## [#](#customizing-the-ternary-filter-option-labels)Customizing the ternary filter option labels

You may customize the labels used for each state of the ternary filter. The true option label can be customized using the `trueLabel()` method. The false option label can be customized using the `falseLabel()` method. The blank (default) option label can be customized using the `placeholder()` method:

 use Illuminate\Database\Eloquent\Builder;
 use Filament\Tables\Filters\TernaryFilter;
 
 TernaryFilter::make('email_verified_at')
 ->label('Email verification')
 ->nullable()
 ->placeholder('All users')
 ->trueLabel('Verified users')
 ->falseLabel('Not verified users')

## [#](#customizing-how-a-ternary-filter-modifies-the-query)Customizing how a ternary filter modifies the query

You may customize how the query changes for each state of the ternary filter, use the `queries()` method:

 use Illuminate\Database\Eloquent\Builder;
 use Filament\Tables\Filters\TernaryFilter;
 
 TernaryFilter::make('email_verified_at')
 ->label('Email verification')
 ->placeholder('All users')
 ->trueLabel('Verified users')
 ->falseLabel('Not verified users')
 ->queries(
 true: fn (Builder $query) => $query->whereNotNull('email_verified_at'),
 false: fn (Builder $query) => $query->whereNull('email_verified_at'),
 blank: fn (Builder $query) => $query, // In this example, we do not want to filter the query when it is blank.
 )

## [#](#filtering-soft-deletable-records)Filtering soft-deletable records

The `TrashedFilter` can be used to filter soft-deleted records. It is a type of ternary filter that is built-in to Filament. You can use it like so:

 use Filament\Tables\Filters\TrashedFilter;
 
 TrashedFilter::make()

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/03-filters/03-ternary.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)