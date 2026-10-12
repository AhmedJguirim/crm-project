<?php

namespace App\Filament\Support;

use App\Filament\Support\StateCasts\RecordKeyIsReadable;
use App\Filament\Support\StateCasts\RecordKeysStateCast;
use App\Filament\Support\StateCasts\RecordKeyStateCast;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;

/**
 * Every form field whose state is a record id goes through this class (BUG-31): a forged non-integer value never reaches a query.
 */
final class RecordKeyField
{
    /**
     * A single record-id select.
     */
    public static function select(string $name): Select
    {
        return Select::make($name)
            ->stateCast(new RecordKeyStateCast)
            ->rules(['bail', 'integer', new RecordKeyIsReadable]);
    }

    /**
     * A select of several record ids: the field itself must be a list (a scalar is refused, not read as no selection), each item a readable id.
     */
    public static function multiSelect(string $name): Select
    {
        return Select::make($name)
            ->multiple()
            ->stateCast(new RecordKeysStateCast)
            ->rules(['array'])
            ->nestedRecursiveRules(['bail', 'integer', new RecordKeyIsReadable]);
    }

    /**
     * A hidden field holding a record id.
     */
    public static function hidden(string $name): Hidden
    {
        return Hidden::make($name)
            ->stateCast(new RecordKeyStateCast)
            ->rules(['bail', 'integer', new RecordKeyIsReadable]);
    }
}
