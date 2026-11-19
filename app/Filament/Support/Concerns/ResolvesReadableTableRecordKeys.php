<?php

namespace App\Filament\Support\Concerns;

use App\Filament\Support\StateCasts\RecordKeyStateCast;
use App\Support\Livewire\ForgedPayload;
use Illuminate\Database\Eloquent\Model;

/**
 * Refuses a forged table record key (BUG-89) with a logged 419 before it reaches the database: on a table with a
 * query, a key that is not a readable id (`abc`, `1.5`, a 20-digit number) would make PostgreSQL refuse the bigint
 * lookup and the request would answer 500. A non-scalar key is refused on every table.
 */
trait ResolvesReadableTableRecordKeys
{
    /**
     * @return Model|array<string, mixed>|null
     */
    public function getTableRecord(mixed $key): Model|array|null
    {
        return parent::getTableRecord($this->readableTableRecordKey($key));
    }

    protected function readableTableRecordKey(mixed $key): ?string
    {
        if ($key === null) {
            return null;
        }

        if (! is_string($key) && ! is_int($key)) {
            ForgedPayload::refuse('table_record_key', $this);
        }

        if ($this->getTable()->hasQuery() && RecordKeyStateCast::toKey($key) === null) {
            ForgedPayload::refuse('table_record_key', $this);
        }

        return (string) $key;
    }
}
