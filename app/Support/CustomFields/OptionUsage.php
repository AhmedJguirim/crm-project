<?php

namespace App\Support\CustomFields;

use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\Contact;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Which records of an organization still store an option of a select or multi-select custom field: contacts for
 * contact fields, companies for company fields, trashed ones included since they can be restored.
 */
class OptionUsage
{
    private const NAMES_SHOWN = 3;

    /** @return Builder<Contact|Company> */
    public static function recordsUsingOption(CustomField|CompanyCustomField $field, string $value): Builder
    {
        $query = $field instanceof CompanyCustomField
            ? Company::query()->withTrashed()
            : Contact::query()->withTrashed();

        $query = $query->forOrganization($field->organization_id);

        return $field->type === 'multiselect'
            ? $query->whereRaw('custom_field_values->? @> CAST(? AS jsonb)', [$field->key, json_encode([$value])])
            : $query->whereRaw('custom_field_values->>? = ?', [$field->key, $value]);
    }

    /**
     * The message of the options that are still stored on records, or null when none of the given values is in use.
     *
     * @param  array<int, string>  $removedValues
     */
    public static function describeRemovedOptions(CustomField|CompanyCustomField $field, array $removedValues): ?string
    {
        $noun = $field instanceof CompanyCustomField ? 'company' : 'contact';
        $labels = $field->optionLabels();
        $messages = [];

        foreach ($removedValues as $value) {
            $records = self::recordsUsingOption($field, $value);
            $count = (clone $records)->count();

            if ($count === 0) {
                continue;
            }

            $names = (clone $records)->orderBy('name')->limit(self::NAMES_SHOWN)->pluck('name')->implode(', ');
            $others = $count > self::NAMES_SHOWN ? ' and '.($count - self::NAMES_SHOWN).' more' : '';
            $countedNoun = Str::plural($noun, $count);
            $plural = Str::plural($noun);
            $label = $labels[$value] ?? $value;

            $messages[] = "The option \"{$label}\" is still set on {$count} {$countedNoun} ({$names}{$others}). Remove it from those {$plural} first, or keep the option.";
        }

        return $messages === [] ? null : implode(' ', $messages);
    }
}
