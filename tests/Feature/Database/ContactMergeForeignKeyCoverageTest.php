<?php

use App\Services\Contacts\ContactMerger;
use Illuminate\Support\Facades\DB;

it('handles every foreign key that references contacts', function () {
    $foreignKeys = collect(DB::select(<<<'SQL'
        select cl.relname || '.' || att.attname as fk
        from pg_constraint con
        join pg_class cl on cl.oid = con.conrelid
        join pg_attribute att on att.attrelid = con.conrelid and att.attnum = any(con.conkey)
        where con.contype = 'f' and con.confrelid = 'contacts'::regclass
        order by 1
        SQL))->pluck('fk')->sort()->values()->all();

    $handled = array_keys(ContactMerger::HANDLED_FOREIGN_KEYS);
    sort($handled);

    expect($foreignKeys)->toBe($handled);
});

it('says how each foreign key is handled', function () {
    expect(ContactMerger::HANDLED_FOREIGN_KEYS)->each->toBeIn(['repoint', 'union', 'resync', 'merge link']);
});
