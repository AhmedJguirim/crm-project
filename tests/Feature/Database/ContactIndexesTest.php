<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has no unused custom field index on contacts', function () {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'contacts'"))
        ->pluck('indexdef', 'indexname');

    expect($indexes->keys()->all())->not->toContain('contacts_custom_field_values_gin')
        ->and($indexes->filter(fn (string $definition): bool => str_contains($definition, 'USING gin')))->toBeEmpty()
        ->and($indexes->keys()->all())->toContain('contacts_organization_id_index', 'contacts_organization_id_email_unique');
});

it('has no deleted_at column on segments', function () {
    expect(Schema::hasColumn('segments', 'deleted_at'))->toBeFalse();
});

it('has no migration that another one undoes', function () {
    $files = collect(glob(database_path('migrations/*.php')))->map(fn (string $file): string => basename($file));

    expect($files->filter(fn (string $file): bool => preg_match('/gin_index|soft_deletes_to_segments|soft_deletes_from_segments/', $file) === 1)->all())->toBe([]);
});
