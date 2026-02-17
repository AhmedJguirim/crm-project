# Source: https://filamentphp.com/docs/4.x/tables/columns/text

Tables \- Columns 

# Text column 

## [#](#introduction)Introduction

Text columns display simple text:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')

![Text column](/docs/4.x/images/light/tables/columns/text/simple.jpg) ![Text column](/docs/4.x/images/dark/tables/columns/text/simple.jpg)

## [#](#customizing-the-color)Customizing the color

You may set a [color](../../styling/colors) for the text:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('status')
 ->color('primary')

As well as allowing a static value, the `color()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column in the primary color](/docs/4.x/images/light/tables/columns/text/color.jpg) ![Text column in the primary color](/docs/4.x/images/dark/tables/columns/text/color.jpg)

## [#](#adding-an-icon)Adding an icon

Text columns may also have an [icon](../../styling/icons):

 use Filament\Tables\Columns\TextColumn;
 use Filament\Support\Icons\Heroicon;
 
 TextColumn::make('email')
 ->icon(Heroicon::Envelope)

The `icon()` method also accepts a function to dynamically calculate the icon. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column with icon](/docs/4.x/images/light/tables/columns/text/icon.jpg) ![Text column with icon](/docs/4.x/images/dark/tables/columns/text/icon.jpg)

You may set the position of an icon using `iconPosition()`:

 use Filament\Tables\Columns\TextColumn;
 use Filament\Support\Enums\IconPosition;
 use Filament\Support\Icons\Heroicon;
 
 TextColumn::make('email')
 ->icon(Heroicon::Envelope)
 ->iconPosition(IconPosition::After) // `IconPosition::Before` or `IconPosition::After`

The `iconPosition()` method also accepts a function to dynamically calculate the icon position. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column with icon after](/docs/4.x/images/light/tables/columns/text/icon-after.jpg) ![Text column with icon after](/docs/4.x/images/dark/tables/columns/text/icon-after.jpg)

The icon color defaults to the text color, but you may customize the icon [color](../../styling/colors) separately using `iconColor()`:

 use Filament\Tables\Columns\TextColumn;
 use Filament\Support\Icons\Heroicon;
 
 TextColumn::make('email')
 ->icon(Heroicon::Envelope)
 ->iconColor('primary')

The `iconColor()` method also accepts a function to dynamically calculate the icon color. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column with icon in the primary color](/docs/4.x/images/light/tables/columns/text/icon-color.jpg) ![Text column with icon in the primary color](/docs/4.x/images/dark/tables/columns/text/icon-color.jpg)

## [#](#displaying-as-a-badge)Displaying as a “badge”

By default, text is quite plain and has no background color. You can make it appear as a “badge” instead using the `badge()` method. A great use case for this is with statuses, where may want to display a badge with a [color](#customizing-the-color) that matches the status:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('status')
 ->badge()
 ->color(fn (string $state): string => match ($state) {
 'draft' => 'gray',
 'reviewing' => 'warning',
 'published' => 'success',
 'rejected' => 'danger',
 })

![Text column as badge](/docs/4.x/images/light/tables/columns/text/badge.jpg) ![Text column as badge](/docs/4.x/images/dark/tables/columns/text/badge.jpg)

You may add other things to the badge, like an [icon](#adding-an-icon).

Optionally, you may pass a boolean value to control if the text should be in a badge or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('status')
 ->badge(FeatureFlag::active())

As well as allowing a static value, the `badge()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#formatting)Formatting

When using a text column, you may want the actual outputted text in the UI to differ from the raw [state](overview#column-content-state) of the column, which is often automatically retrieved from an Eloquent model. Formatting the state allows you to preserve the integrity of the raw data while also allowing it to be presented in a more user-friendly way.

To format the state of a text column without changing the state itself, you can use the `formatStateUsing()` method. This method accepts a function that takes the state as an argument and returns the formatted state:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('status')
 ->formatStateUsing(fn (string $state): string => __("statuses.{$state}"))

In this case, the `status` column in the database might contain values like `draft`, `reviewing`, `published`, or `rejected`, but the formatted state will be the translated version of these values.

The function passed to `formatStateUsing()` can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#date-formatting)Date formatting

Instead of passing a function to `formatStateUsing()`, you may use the `date()`, `dateTime()`, and `time()` methods to format the column’s state using [PHP date formatting tokens](https://www.php.net/manual/en/datetime.format.php):

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->date()
 
 TextColumn::make('created_at')
 ->dateTime()
 
 TextColumn::make('created_at')
 ->time()

You may customize the date format by passing a custom format string to the `date()`, `dateTime()`, or `time()` method. You may use any [PHP date formatting tokens](https://www.php.net/manual/en/datetime.format.php):

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->date('M j, Y')
 
 TextColumn::make('created_at')
 ->dateTime('M j, Y H:i:s')
 
 TextColumn::make('created_at')
 ->time('H:i:s')

As well as allowing static values, the `date()`, `dateTime()`, and `time()` methods also accept a function to dynamically calculate the format. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#date-formatting-using-carbon-macro-formats)Date formatting using Carbon macro formats

You may use also the `isoDate()`, `isoDateTime()`, and `isoTime()` methods to format the column’s state using [Carbon’s macro-formats](https://carbon.nesbot.com/docs/#available-macro-formats):

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->isoDate()
 
 TextColumn::make('created_at')
 ->isoDateTime()
 
 TextColumn::make('created_at')
 ->isoTime()

You may customize the date format by passing a custom macro format string to the `isoDate()`, `isoDateTime()`, or `isoTime()` method. You may use any [Carbon’s macro-formats](https://carbon.nesbot.com/docs/#available-macro-formats):

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->isoDate('L')
 
 TextColumn::make('created_at')
 ->isoDateTime('LLL')
 
 TextColumn::make('created_at')
 ->isoTime('LT')

As well as allowing static values, the `isoDate()`, `isoDateTime()`, and `isoTime()` methods also accept a function to dynamically calculate the format. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#relative-date-formatting)Relative date formatting

You may use the `since()` method to format the column’s state using [Carbon’s `diffForHumans()`](https://carbon.nesbot.com/docs/#api-humandiff):

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->since()

#### [#](#displaying-a-formatting-date-in-a-tooltip)Displaying a formatting date in a tooltip

Additionally, you can use the `dateTooltip()`, `dateTimeTooltip()`, `timeTooltip()`, `isoDateTooltip()`, `isoDateTimeTooltip()`, `isoTime()`, `isoTimeTooltip()`, or `sinceTooltip()` method to display a formatted date in a [tooltip](overview#adding-a-tooltip-to-an-column), often to provide extra information:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->since()
 ->dateTooltip() // Accepts a custom PHP date formatting string
 
 TextColumn::make('created_at')
 ->since()
 ->dateTimeTooltip() // Accepts a custom PHP date formatting string
 
 TextColumn::make('created_at')
 ->since()
 ->timeTooltip() // Accepts a custom PHP date formatting string
 
 TextColumn::make('created_at')
 ->since()
 ->isoDateTooltip() // Accepts a custom Carbon macro format string
 
 TextColumn::make('created_at')
 ->since()
 ->isoDateTimeTooltip() // Accepts a custom Carbon macro format string
 
 TextColumn::make('created_at')
 ->since()
 ->isoTimeTooltip() // Accepts a custom Carbon macro format string
 
 TextColumn::make('created_at')
 ->dateTime()
 ->sinceTooltip()

#### [#](#setting-the-timezone-for-date-formatting)Setting the timezone for date formatting

Each of the date formatting methods listed above also accepts a `timezone` argument, which allows you to convert the time set in the state to a different timezone:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->dateTime(timezone: 'America/New_York')

You can also pass a timezone to the `timezone()` method of the column to apply a timezone to all date-time formatting methods at once:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('created_at')
 ->timezone('America/New_York')
 ->dateTime()

As well as allowing static values, the `timezone()` method also accepts a function to dynamically calculate the timezone. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
If you do not pass a `timezone()` to the column, it will use Filament’s default timezone. You can set Filament’s default timezone using the `FilamentTimezone::set()` method in the `boot()` method of a service provider such as `AppServiceProvider`:

 use Filament\Support\Facades\FilamentTimezone;
 
 public function boot(): void
 {
 FilamentTimezone::set('America/New_York');
 }

This is useful if you want to set a default timezone for all text columns in your application. It is also used in other places where timezones are used in Filament.

NOTE

Filament’s default timezone will only apply when the column stores a time. If the column stores a date only (`date()` instead of `dateTime()`), the timezone will not be applied. This is to prevent timezone shifts when storing dates without times.

### [#](#number-formatting)Number formatting

Instead of passing a function to `formatStateUsing()`, you can use the `numeric()` method to format a column as a number:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('stock')
 ->numeric()

If you would like to customize the number of decimal places used to format the number with, you can use the `decimalPlaces` argument:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('stock')
 ->numeric(decimalPlaces: 0)

As well as allowing static values, the `decimalPlaces` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, your app’s locale will be used to format the number suitably. If you would like to customize the locale used, you can pass it to the `locale` argument:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('stock')
 ->numeric(locale: 'nl')

As well as allowing static values, the `locale` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#money-formatting)Money formatting

Instead of passing a function to `formatStateUsing()`, you can use the `money()` method to easily format amounts of money, in any currency:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('price')
 ->money('EUR')

As well as allowing static values, the `money()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
There is also a `divideBy` argument for `money()` that allows you to divide the original value by a number before formatting it. This could be useful if your database stores the price in cents, for example:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('price')
 ->money('EUR', divideBy: 100)

As well as allowing static values, the `divideBy` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, your app’s locale will be used to format the money suitably. If you would like to customize the locale used, you can pass it to the `locale` argument:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('price')
 ->money('EUR', locale: 'nl')

As well as allowing static values, the `locale` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
If you would like to customize the number of decimal places used to format the number with, you can use the `decimalPlaces` argument:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('price')
 ->money('EUR', decimalPlaces: 3)

As well as allowing static values, the `decimalPlaces` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#rendering-markdown)Rendering Markdown

If your column value is Markdown, you may render it using `markdown()`:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->markdown()

Optionally, you may pass a boolean value to control if the text should be rendered as Markdown or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->markdown(FeatureFlag::active())

As well as allowing a static value, the `markdown()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#rendering-html)Rendering HTML

If your column value is HTML, you may render it using `html()`:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->html()

Optionally, you may pass a boolean value to control if the text should be rendered as HTML or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->html(FeatureFlag::active())

As well as allowing a static value, the `html()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#rendering-raw-html-without-sanitization)Rendering raw HTML without sanitization

If you use this method, then the HTML will be sanitized to remove any potentially unsafe content before it is rendered. If you’d like to opt out of this behavior, you can wrap the HTML in an `HtmlString` object by formatting it:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Support\HtmlString;
 
 TextColumn::make('description')
 ->formatStateUsing(fn (string $state): HtmlString => new HtmlString($state))

NOTE

Be cautious when rendering raw HTML, as it may contain malicious content, which can lead to security vulnerabilities in your app such as cross-site scripting (XSS) attacks. Always ensure that the HTML you are rendering is safe before using this method.

Alternatively, you can return a `view()` object from the `formatStateUsing()` method, which will also not be sanitized:

 use Filament\Tables\Columns\TextColumn;
 use Illuminate\Contracts\View\View;
 
 TextColumn::make('description')
 ->formatStateUsing(fn (string $state): View => view(
 'filament.tables.columns.description-column-content',
 ['state' => $state],
 ))

## [#](#displaying-a-description)Displaying a description

Descriptions may be used to easily render additional text above or below the column contents.

You can display a description below the contents of a text column using the `description()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->description(fn (Post $record): string => $record->description)

The function passed to `description()` can inject various utilities as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column with description](/docs/4.x/images/light/tables/columns/text/description.jpg) ![Text column with description](/docs/4.x/images/dark/tables/columns/text/description.jpg)

By default, the description is displayed below the main text, but you can move it using `'above'` as the second parameter:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('title')
 ->description(fn (Post $record): string => $record->description, position: 'above')

![Text column with description above the content](/docs/4.x/images/light/tables/columns/text/description-above.jpg) ![Text column with description above the content](/docs/4.x/images/dark/tables/columns/text/description-above.jpg)

## [#](#listing-multiple-values)Listing multiple values

Multiple values can be rendered in a text column if its [state](overview#column-content-state) is an array. This can happen if you are using an `array` cast on an Eloquent attribute, an Eloquent relationship with multiple results, or if you have passed an array to the [`state()` method](overview#setting-the-state-of-an-column). If there are multiple values inside your text column, they will be comma-separated. You may use the `listWithLineBreaks()` method to display them on new lines instead:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->listWithLineBreaks()

Optionally, you may pass a boolean value to control if the text should have line breaks between each item or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->listWithLineBreaks(FeatureFlag::active())

As well as allowing a static value, the `listWithLineBreaks()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#adding-bullet-points-to-the-list)Adding bullet points to the list

You may add a bullet point to each list item using the `bulleted()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->bulleted()

Optionally, you may pass a boolean value to control if the text should have bullet points or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->bulleted(FeatureFlag::active())

As well as allowing a static value, the `bulleted()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#limiting-the-number-of-values-in-the-list)Limiting the number of values in the list

You can limit the number of values in the list using the `limitList()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->listWithLineBreaks()
 ->limitList(3)

As well as allowing a static value, the `limitList()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#expanding-the-limited-list)Expanding the limited list

You can allow the limited items to be expanded and collapsed, using the `expandableLimitedList()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->listWithLineBreaks()
 ->limitList(3)
 ->expandableLimitedList()

NOTE

This is only a feature for `listWithLineBreaks()` or `bulleted()`, where each item is on its own line.

Optionally, you may pass a boolean value to control if the text should be expandable or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('authors.name')
 ->listWithLineBreaks()
 ->limitList(3)
 ->expandableLimitedList(FeatureFlag::active())

As well as allowing a static value, the `expandableLimitedList()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#splitting-a-single-value-into-multiple-list-items)Splitting a single value into multiple list items

If you want to “explode” a text string from your model into multiple list items, you can do so with the `separator()` method. This is useful for displaying comma-separated tags [as badges](#displaying-as-a-badge), for example:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('tags')
 ->badge()
 ->separator(',')

As well as allowing a static value, the `separator()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#customizing-the-text-size)Customizing the text size

Text columns have small font size by default, but you may change this to `TextSize::ExtraSmall`, `TextSize::Medium`, or `TextSize::Large`.

For instance, you may make the text larger using `size(TextSize::Large)`:

 use Filament\Tables\Columns\TextColumn;
 use Filament\Support\Enums\TextSize;
 
 TextColumn::make('title')
 ->size(TextSize::Large)

![Text column in a large font size](/docs/4.x/images/light/tables/columns/text/large.jpg) ![Text column in a large font size](/docs/4.x/images/dark/tables/columns/text/large.jpg)

## [#](#customizing-the-font-weight)Customizing the font weight

Text columns have regular font weight by default, but you may change this to any of the following options: `FontWeight::Thin`, `FontWeight::ExtraLight`, `FontWeight::Light`, `FontWeight::Medium`, `FontWeight::SemiBold`, `FontWeight::Bold`, `FontWeight::ExtraBold` or `FontWeight::Black`.

For instance, you may make the font bold using `weight(FontWeight::Bold)`:

 use Filament\Tables\Columns\TextColumn;
 use Filament\Support\Enums\FontWeight;
 
 TextColumn::make('title')
 ->weight(FontWeight::Bold)

As well as allowing a static value, the `weight()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column in a bold font](/docs/4.x/images/light/tables/columns/text/bold.jpg) ![Text column in a bold font](/docs/4.x/images/dark/tables/columns/text/bold.jpg)

## [#](#customizing-the-font-family)Customizing the font family

You can change the text font family to any of the following options: `FontFamily::Sans`, `FontFamily::Serif` or `FontFamily::Mono`.

For instance, you may make the font monospaced using `fontFamily(FontFamily::Mono)`:

 use Filament\Support\Enums\FontFamily;
 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->fontFamily(FontFamily::Mono)

As well as allowing a static value, the `fontFamily()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
![Text column in a monospaced font](/docs/4.x/images/light/tables/columns/text/mono.jpg) ![Text column in a monospaced font](/docs/4.x/images/dark/tables/columns/text/mono.jpg)

## [#](#handling-long-text)Handling long text

### [#](#limiting-text-length)Limiting text length

You may `limit()` the length of the column’s value:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->limit(50)

As well as allowing a static value, the `limit()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, when text is truncated, an ellipsis (`...`) is appended to the end of the text. You may customize this by passing a custom string to the `end` argument:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->limit(50, end: ' (more)')

As well as allowing a static value, the `end` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
You may also reuse the value that is being passed to `limit()` in a function, by getting it using the `getCharacterLimit()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->limit(50)
 ->tooltip(function (TextColumn $column): ?string {
 $state = $column->getState();
 
 if (strlen($state) <= $column->getCharacterLimit()) {
 return null;
 }
 
 // Only render the tooltip if the column contents exceeds the length limit.
 return $state;
 })

### [#](#limiting-word-count)Limiting word count

You may limit the number of `words()` displayed in the column:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->words(10)

As well as allowing a static value, the `words()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
By default, when text is truncated, an ellipsis (`...`) is appended to the end of the text. You may customize this by passing a custom string to the `end` argument:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->words(10, end: ' (more)')

As well as allowing a static value, the `end` argument also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
### [#](#allowing-text-wrapping)Allowing text wrapping

By default, text will not wrap to the next line if it exceeds the width of the container. You can enable this behavior using the `wrap()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->wrap()

Optionally, you may pass a boolean value to control if the text should wrap or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->wrap(FeatureFlag::active())

As well as allowing a static value, the `wrap()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
#### [#](#limiting-text-to-a-specific-number-of-lines)Limiting text to a specific number of lines

You may want to limit text to a specific number of lines instead of limiting it to a fixed length. Clamping text to a number of lines is useful in responsive interfaces where you want to ensure a consistent experience across all screen sizes. This can be achieved using the `lineClamp()` method:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('description')
 ->wrap()
 ->lineClamp(2)

As well as allowing a static value, the `lineClamp()` method also accepts a function to dynamically calculate it. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
## [#](#allowing-the-text-to-be-copied-to-the-clipboard)Allowing the text to be copied to the clipboard

You may make the text copyable, such that clicking on the column copies the text to the clipboard, and optionally specify a custom confirmation message and duration in milliseconds:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->copyable()
 ->copyMessage('Email address copied')
 ->copyMessageDuration(1500)

![Text column with a button to copy it](/docs/4.x/images/light/tables/columns/text/copyable.jpg) ![Text column with a button to copy it](/docs/4.x/images/dark/tables/columns/text/copyable.jpg)

Optionally, you may pass a boolean value to control if the text should be copyable or not:

 use Filament\Tables\Columns\TextColumn;
 
 TextColumn::make('email')
 ->copyable(FeatureFlag::active())

As well as allowing static values, the `copyable()`, `copyMessage()`, and `copyMessageDuration()` methods also accept functions to dynamically calculate them. You can inject various utilities into the function as parameters. [ Learn more about utility injection. ](/docs/4.x/tables/columns/overview#column-utility-injection) Utility | Type | Parameter | Description 
---|---|---|--- 
Column | `Filament\Tables\Columns\Column` | `$column` | The current column instance. 
Livewire | `Livewire\Component` | `$livewire` | The Livewire component instance. 
Eloquent record | `?Illuminate\Database\Eloquent\Model` | `$record` | The Eloquent record for the current table row. 
Row loop | `stdClass` | `$rowLoop` | The [row loop](https://laravel.com/docs/blade#the-loop-variable) object for the current table row. 
State | `mixed` | `$state` | The current value of the column, based on the current table row. 
Table | `Filament\Tables\Table` | `$table` | The current table instance. 
 
NOTE

This feature only works when SSL is enabled for the app.

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/02-text.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)