<?php

/**
 * Segment membership stays correct because every write to the tracked data fires model events that queue a resync.
 * This test scans the application source for writes that skip those events, and fails on the ones that aren't in
 * the allow-list below.
 *
 * Tracked data: contacts, deals, activities, tags, companies, company types, and the contact_tag / company_contact
 * links. When a hit is justified (the same code queues the resync, or the data isn't tracked), add it to the
 * allow-list with the reason.
 */

/**
 * A reviewed exception: 'relative/path.php' => ['pattern' => 'reason'].
 *
 * @return array<string, array<string, string>>
 */
function eventBypassingWritesAllowList(): array
{
    return [
        'Services/ContactImportService.php' => [
            'withoutEvents' => 'On purpose: the import skips per-contact resyncs, and ProcessContactImportJob queues a full sync of every published segment when it finishes (H1).',
        ],
    ];
}

const BYPASS_MODELS = 'Contact|Deal|Activity|Tag|Company|CompanyType';
const BYPASS_TABLES = 'contact_tag|company_contact|contacts|deals|contact_activities|tags|companies';
const BYPASS_METHODS = 'update|delete|insert\w*|upsert|increment|decrement';

/** Calls that return a model instance: what follows them runs through model events. */
const BYPASS_INSTANCE_METHODS = 'first|firstOrFail|sole|find|findOrFail|firstOrCreate|firstOrNew|updateOrCreate|create|forceCreate';

/**
 * The patterns, by name. Each one is matched against a whole statement (up to the next semicolon).
 *
 * @return array<string, string>
 */
function eventBypassingPatterns(): array
{
    return [
        'quietly' => '/(?:->|::)\s*(?:save|update|create|delete)Quietly\s*\(/',
        'withoutEvents' => '/(?:->|::)\s*withoutEvents\s*\(/',
        'model-query' => '/\b(?:'.BYPASS_MODELS.')::(?!(?:'.BYPASS_INSTANCE_METHODS.')\s*\()(?:(?!->(?:'.BYPASS_INSTANCE_METHODS.')\s*\()[^;])*?->(?:'.BYPASS_METHODS.')\s*\(/s',
        'table-query' => '/\bDB::table\(\s*[\'"](?:'.BYPASS_TABLES.')[\'"]\s*\)[^;]*?->(?:'.BYPASS_METHODS.')\s*\(/s',
    ];
}

/**
 * Splits PHP source into statements, so a pattern can't match across them.
 *
 * @return array<int, string>
 */
function eventBypassingStatements(string $source): array
{
    return preg_split('/;/', $source) ?: [];
}

/**
 * The patterns found in each file under the root, by file relative to it.
 *
 * @return array<string, array<int, string>>
 */
function scanForEventBypassingWrites(string $root): array
{
    $hits = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);

        foreach (eventBypassingStatements(file_get_contents($file->getPathname())) as $statement) {
            foreach (eventBypassingPatterns() as $name => $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    $hits[$relative][] = $name;
                }
            }
        }
    }

    return array_map(fn (array $names): array => array_values(array_unique($names)), $hits);
}

/**
 * @param  array<string, string>  $files  Source by relative path.
 * @return array<string, array<int, string>>
 */
function scanFixtures(array $files): array
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'event-bypass-'.uniqid();
    mkdir($root);

    foreach ($files as $path => $source) {
        file_put_contents("{$root}/{$path}", "<?php\n\n{$source}\n");
    }

    $hits = scanForEventBypassingWrites($root);

    array_map('unlink', glob("{$root}/*"));
    rmdir($root);

    return $hits;
}

it('has no event-bypassing write on tracked data that is not allow-listed', function () {
    $allowList = eventBypassingWritesAllowList();
    $unexpected = [];

    foreach (scanForEventBypassingWrites(dirname(__DIR__, 2).'/app') as $file => $patterns) {
        foreach ($patterns as $pattern) {
            if (! isset($allowList[$file][$pattern])) {
                $unexpected[] = "{$file} → {$pattern}";
            }
        }
    }

    expect($unexpected)->toBe([], "These writes skip model events, so segments could stay wrong. Queue a segment resync for the affected contacts (ResyncContactSegments::dispatchForContacts or SyncSegmentMembership), then add the file to the allow-list of this test with the reason:\n".implode("\n", $unexpected));
});

it('has no allow-list entry that matches nothing any more', function () {
    $hits = scanForEventBypassingWrites(dirname(__DIR__, 2).'/app');
    $stale = [];

    foreach (eventBypassingWritesAllowList() as $file => $patterns) {
        foreach (array_keys($patterns) as $pattern) {
            if (! in_array($pattern, $hits[$file] ?? [], true)) {
                $stale[] = "{$file} → {$pattern}";
            }
        }
    }

    expect($stale)->toBe([], "These allow-list entries no longer match anything, remove them:\n".implode("\n", $stale));
});

it('reports a new bypassing write with its file and pattern', function () {
    $hits = scanFixtures(['Bad.php' => "Deal::where('stage', 'lead')->update(['stage' => 'won']);"]);

    expect($hits)->toBe(['Bad.php' => ['model-query']]);
});

it('detects every kind of bypassing write', function (string $code, string $pattern) {
    expect(scanFixtures(['Fixture.php' => $code]))->toBe(['Fixture.php' => [$pattern]]);
})->with([
    'saveQuietly' => ['$contact->saveQuietly();', 'quietly'],
    'updateQuietly' => ['$deal->updateQuietly([\'stage\' => \'won\']);', 'quietly'],
    'createQuietly' => ['Contact::createQuietly([\'name\' => \'x\']);', 'quietly'],
    'withoutEvents' => ['Contact::withoutEvents(fn () => null);', 'withoutEvents'],
    'query builder delete' => ['Tag::query()->whereKey(1)->delete();', 'model-query'],
    'static update' => ['Activity::where(\'id\', 1)->update([\'notes\' => \'x\']);', 'model-query'],
    'increment' => ['Company::where(\'id\', 1)->increment(\'x\');', 'model-query'],
    'company type delete' => ['CompanyType::whereKey(1)->delete();', 'model-query'],
    'raw pivot insert' => ['DB::table(\'contact_tag\')->insert([\'contact_id\' => 1]);', 'table-query'],
    'raw contacts update' => ['DB::table(\'contacts\')->where(\'id\', 1)->update([\'name\' => \'x\']);', 'table-query'],
    'raw insert or ignore' => ['DB::table(\'company_contact\')->insertOrIgnore([[\'contact_id\' => 1]]);', 'table-query'],
]);

it('does not flag writes that fire model events or touch other tables', function (string $code) {
    expect(scanFixtures(['Fixture.php' => $code]))->toBe([]);
})->with([
    'instance update' => ['$deal->update([\'stage\' => \'won\']);'],
    'instance delete' => ['$tag->delete();'],
    'found model update' => ['Deal::find(1)->update([\'stage\' => \'won\']);'],
    'first model update' => ['Contact::where(\'id\', 1)->first()->update([\'name\' => \'x\']);'],
    'create' => ['Contact::create([\'name\' => \'x\']);'],
    'reading' => ['Contact::query()->where(\'id\', 1)->count();'],
    'other model' => ['Segment::query()->whereKey(1)->update([\'is_syncing\' => false]);'],
    'other table' => ['DB::table(\'segments\')->where(\'id\', 1)->update([\'name\' => \'x\']);'],
    'pivot of segments' => ['DB::table(\'contact_segment\')->insert([\'contact_id\' => 1]);'],
]);

it('keeps statements apart', function () {
    $hits = scanFixtures(['Fixture.php' => "Contact::query()->count();\n\$other->update(['a' => 1]);"]);

    expect($hits)->toBe([]);
});
