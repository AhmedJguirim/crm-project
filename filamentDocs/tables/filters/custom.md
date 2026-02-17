# Source: https://filamentphp.com/docs/4.x/tables/filters/custom

Tables \- Filters 

# Custom filters 

## [#](#custom-filter-schemas)Custom filter schemas

You may use [schema components](../../schemas) to create custom filters. The data from the custom filter schema is available in the `$data` array of the `query()` callback:

 use Filament\Forms\Components\DatePicker;
 use Filament\Tables\Filters\Filter;
 use Illuminate\Database\Eloquent\Builder;
 
 Filter::make('created_at')
 ->schema([
 DatePicker::make('created_from'),
 DatePicker::make('created_until'),
 ])
 ->query(function (Builder $query, array $data): Builder {
 return $query
 ->when(
 $data['created_from'],
 fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
 )
 ->when(
 $data['created_until'],
 fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
 );
 })

The `query()` function can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/forms/overview#field-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Field | `Filament\Forms\Components\Field` | `$component` | The current field component instance. 
Data | `array<string, mixed>` | `$data` | The data from the filter's form fields. 
Get function | `Filament\Schemas\Components\Utilities\Get` | `$get` | A function for retrieving values from the current form data. Validation is not run. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent model FQN | `?string<Illuminate\Database\Eloquent\Model>` | `$model` | The Eloquent model FQN for the current schema. 
Operation | `string` | `$operation` | The current operation being performed by the schema. Usually `create`, `edit`, or `view`. 
Query | `Illuminate\Database\Eloquent\Builder` | `$query` | The Eloquent query builder to modify. 
Raw state | `mixed` | `$rawState` | The current value of the field, before state casts were applied. Validation is not run. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current schema. 
State | `mixed` | `$state` | The current value of the field. Validation is not run. 
 
![Table with custom filter schema](/docs/4.x/images/light/tables/filters/custom-form.jpg) ![Table with custom filter schema](/docs/4.x/images/dark/tables/filters/custom-form.jpg)

### [#](#setting-default-values-for-custom-filter-fields)Setting default values for custom filter fields

To customize the default value of a field in a custom filter schema, you may use the `default()` method:

 use Filament\Forms\Components\DatePicker;
 use Filament\Tables\Filters\Filter;
 
 Filter::make('created_at')
 ->schema([
 DatePicker::make('created_from'),
 DatePicker::make('created_until')
 ->default(now()),
 ])

## [#](#active-indicators)Active indicators

When a filter is active, an indicator is displayed above the table content to signal that the table query has been scoped.

![Table with filter indicators](/docs/4.x/images/light/tables/filters/indicators.jpg) ![Table with filter indicators](/docs/4.x/images/dark/tables/filters/indicators.jpg)

By default, the label of the filter is used as the indicator. You can override this using the `indicator()` method:

 use Filament\Tables\Filters\Filter;
 
 Filter::make('is_admin')
 ->label('Administrators only?')
 ->indicator('Administrators')

If you are using a [custom filter schema](#custom-filter-schemas), you should use [`indicateUsing()`](#custom-active-indicators) to display an active indicator.

Please note: if you do not have an indicator for your filter, then the badge-count of how many filters are active in the table will not include that filter.

### [#](#custom-active-indicators)Custom active indicators

Not all indicators are simple, so you may need to use `indicateUsing()` to customize which indicators should be shown at any time.

For example, if you have a custom date filter, you may create a custom indicator that formats the selected date:

 use Carbon\Carbon;
 use Filament\Forms\Components\DatePicker;
 use Filament\Tables\Filters\Filter;
 
 Filter::make('created_at')
 ->schema([DatePicker::make('date')])
 // ...
 ->indicateUsing(function (array $data): ?string {
 if (! $data['date']) {
 return null;
 }
 
 return 'Created at ' . Carbon::parse($data['date'])->toFormattedDateString();
 })

### [#](#multiple-active-indicators)Multiple active indicators

You may even render multiple indicators at once, by returning an array of `Indicator` objects. If you have different fields associated with different indicators, you should set the field using the `removeField()` method on the `Indicator` object to ensure that the correct field is reset when the filter is removed:

 use Carbon\Carbon;
 use Filament\Forms\Components\DatePicker;
 use Filament\Tables\Filters\Filter;
 use Filament\Tables\Filters\Indicator;
 
 Filter::make('created_at')
 ->schema([
 DatePicker::make('from'),
 DatePicker::make('until'),
 ])
 // ...
 ->indicateUsing(function (array $data): array {
 $indicators = [];
 
 if ($data['from'] ?? null) {
 $indicators[] = Indicator::make('Created from ' . Carbon::parse($data['from'])->toFormattedDateString())
 ->removeField('from');
 }
 
 if ($data['until'] ?? null) {
 $indicators[] = Indicator::make('Created until ' . Carbon::parse($data['until'])->toFormattedDateString())
 ->removeField('until');
 }
 
 return $indicators;
 })

### [#](#preventing-indicators-from-being-removed)Preventing indicators from being removed

You can prevent users from removing an indicator using `removable(false)` on an `Indicator` object:

 use Carbon\Carbon;
 use Filament\Tables\Filters\Indicator;
 
 Indicator::make('Created from ' . Carbon::parse($data['from'])->toFormattedDateString())
 ->removable(false)

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/03-filters/05-custom.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)