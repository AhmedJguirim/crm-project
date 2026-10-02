<?php

use Illuminate\Support\Facades\DB;

it('has no unused custom field index on contacts', function () {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'contacts'"))
        ->pluck('indexdef', 'indexname');

    expect($indexes->keys()->all())->not->toContain('contacts_custom_field_values_gin')
        ->and($indexes->filter(fn (string $definition): bool => str_contains($definition, 'USING gin')))->toBeEmpty()
        ->and($indexes->keys()->all())->toContain('contacts_organization_id_index', 'contacts_organization_id_email_unique');
});
