<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Adds an immutable `key` to custom fields and re-keys the contacts'
     * `custom_field_values` from the field id to that key.
     */
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->string('key')->nullable();
        });

        $keysByFieldId = $this->backfillKeys();

        $this->rekeyContactValues($keysByFieldId);

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->string('key')->nullable(false)->change();
            $table->unique(['organization_id', 'key']);
        });
    }

    /** @return array<int, string> */
    private function backfillKeys(): array
    {
        $keysByFieldId = [];

        DB::table('custom_fields')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'name'])
            ->groupBy('organization_id')
            ->each(function ($fields) use (&$keysByFieldId): void {
                $takenKeys = [];

                foreach ($fields as $field) {
                    $baseKey = Str::slug($field->name, '_') ?: 'field';
                    $key = $baseKey;
                    $suffix = 2;

                    while (in_array($key, $takenKeys, true)) {
                        $key = "{$baseKey}_{$suffix}";
                        $suffix++;
                    }

                    $takenKeys[] = $key;
                    $keysByFieldId[$field->id] = $key;

                    DB::table('custom_fields')->where('id', $field->id)->update(['key' => $key]);
                }
            });

        return $keysByFieldId;
    }

    /** @param array<int, string> $keysByFieldId */
    private function rekeyContactValues(array $keysByFieldId): void
    {
        DB::table('contacts')
            ->whereNotNull('custom_field_values')
            ->lazyById()
            ->each(function (object $contact) use ($keysByFieldId): void {
                $values = json_decode($contact->custom_field_values, true) ?: [];
                $rekeyedValues = [];

                foreach ($values as $fieldId => $value) {
                    $rekeyedValues[$keysByFieldId[$fieldId] ?? (string) $fieldId] = $value;
                }

                DB::table('contacts')
                    ->where('id', $contact->id)
                    ->update(['custom_field_values' => json_encode((object) $rekeyedValues)]);
            });
    }
};
