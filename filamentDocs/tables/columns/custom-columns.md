# Source: https://filamentphp.com/docs/4.x/tables/columns/custom-columns

Tables \- Columns 

# Custom columns 

## [#](#introduction)Introduction

You may create your own custom column classes and views, which you can reuse across your project, and even release as a plugin to the community.

To create a custom column class and view, you may use the following command:

 php artisan make:filament-table-column AudioPlayerColumn

This will create the following component class:

 use Filament\Tables\Columns\Column;
 
 class AudioPlayerColumn extends Column
 {
 protected string $view = 'filament.tables.columns.audio-player-column';
 }

It will also create a view file at `resources/views/filament/tables/columns/audio-player-column.blade.php`.

NOTE

Filament table columns are **not** Livewire components. Defining public properties and methods on a table column class will not make them accessible in the Blade view.

## [#](#accessing-the-state-of-the-column-in-the-blade-view)Accessing the state of the column in the Blade view

Inside the Blade view, you may access the [state](overview#column-content-state) of the column using the `$getState()` function:

 <div>
 {{ $getState() }}
 </div>

## [#](#accessing-the-eloquent-record-in-the-blade-view)Accessing the Eloquent record in the Blade view

Inside the Blade view, you may access the current table row’s Eloquent record using the `$record` variable:

 <div>
 {{ $record->name }}
 </div>

## [#](#accessing-the-current-livewire-component-instance-in-the-blade-view)Accessing the current Livewire component instance in the Blade view

Inside the Blade view, you may access the current Livewire component instance using `$this`:

 @php
 use Filament\Resources\Users\RelationManagers\ConferencesRelationManager;
 @endphp
 
 <div>
 @if ($this instanceof ConferencesRelationManager)
 You are editing the conferences of a user.
 @endif
 </div>

## [#](#accessing-the-current-column-instance-in-the-blade-view)Accessing the current column instance in the Blade view

Inside the Blade view, you may access the current column instance using `$column`. You can call public methods on this object to access other information that may not be available in variables:

 <div>
 @if ($column->isLabelHidden())
 This is a new conference.
 @endif
 </div>

## [#](#adding-a-configuration-method-to-a-custom-column-class)Adding a configuration method to a custom column class

You may add a public method to the custom column class that accepts a configuration value, stores it in a protected property, and returns it again from another public method:

 use Filament\Tables\Columns\Column;
 
 class AudioPlayerColumn extends Column
 {
 protected string $view = 'filament.tables.columns.audio-player-column';
 
 protected ?float $speed = null;
 
 public function speed(?float $speed): static
 {
 $this->speed = $speed;
 
 return $this;
 }
 
 public function getSpeed(): ?float
 {
 return $this->speed;
 }
 }

Now, in the Blade view for the custom column, you may access the speed using the `$getSpeed()` function:

 <div>
 {{ $getSpeed() }}
 </div>

Any public method that you define on the custom column class can be accessed in the Blade view as a variable function in this way.

To pass the configuration value to the custom column class, you may use the public method:

 use App\Filament\Tables\Columns\AudioPlayerColumn;
 
 AudioPlayerColumn::make('recording')
 ->speed(0.5)

## [#](#allowing-utility-injection-in-a-custom-column-configuration-method)Allowing utility injection in a custom column configuration method

[Utility injection](overview#column-utility-injection) is a powerful feature of Filament that allows users to configure a component using functions that can access various utilities. You can allow utility injection by ensuring that the parameter type and property type of the configuration allows the user to pass a `Closure`. In the getter method, you should pass the configuration value to the `$this->evaluate()` method, which will inject utilities into the user’s function if they pass one, or return the value if it is static:

 use Closure;
 use Filament\Tables\Columns\Column;
 
 class AudioPlayerColumn extends Column
 {
 protected string $view = 'filament.tables.columns.audio-player-column';
 
 protected float | Closure | null $speed = null;
 
 public function speed(float | Closure | null $speed): static
 {
 $this->speed = $speed;
 
 return $this;
 }
 
 public function getSpeed(): ?float
 {
 return $this->evaluate($this->speed);
 }
 }

Now, you can pass a static value or a function to the `speed()` method, and [inject any utility](overview#component-utility-injection) as a parameter:

 use App\Filament\Tables\Columns\AudioPlayerColumn;
 
 AudioPlayerColumn::make('recording')
 ->speed(fn (Conference $record): float => $record->isGlobal() ? 1 : 0.5)

[Edit on GitHub](https://github.com/filamentphp/filament/edit/4.x/packages/tables/docs/02-columns/10-custom-columns.md)

Still need help? Join our [Discord community](/discord) or open a [GitHub discussion](https://github.com/filamentphp/filament/discussions/new/choose)