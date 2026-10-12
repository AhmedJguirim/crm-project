<?php

namespace App\Exceptions;

use App\Models\Company;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Tag;
use App\Services\Segments\SegmentUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Thrown when a change would break the conditions of segments referencing a record.
 */
class UsedInSegmentsException extends LogicException
{
    /** @param  Collection<int, Segment>  $segments */
    public static function cannotDelete(Model $record, Collection $segments): self
    {
        return new self(ucfirst(self::describe($record)).' cannot be deleted: it is used in the conditions of '
            .SegmentUsage::describeSegments($segments).'. Remove it from those conditions first.');
    }

    /** @param  array<string, array<int, string>>  $usedOptions  option value => segment names */
    public static function cannotRemoveOptions(CustomField $field, array $usedOptions): self
    {
        $details = collect($usedOptions)
            ->map(fn (array $segmentNames, string $value): string => "\"{$value}\" (used by ".implode(', ', $segmentNames).')')
            ->join(', ');

        return new self(ucfirst(self::describe($field))." options used in segment conditions cannot be removed: {$details}.");
    }

    private static function describe(Model $record): string
    {
        $kind = match (true) {
            $record instanceof CustomField => 'custom field',
            $record instanceof Tag => 'tag',
            $record instanceof Company => 'company',
            $record instanceof CompanyType => 'company type',
            default => strtolower(class_basename($record)),
        };

        return "the {$kind} \"{$record->getAttribute('name')}\"";
    }
}
