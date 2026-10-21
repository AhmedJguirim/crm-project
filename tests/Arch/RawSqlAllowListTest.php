<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, array{constant?: string, dynamic?: string}> every file of `app/` with a raw SQL call, by relative path, with the reason each kind of call is acceptable
 */
function rawSqlAllowList(): array
{
    return [
        'Console/Commands/ReportDuplicateCustomFieldValuesCommand.php' => [
            'constant' => 'read-only duplicate report; the custom field key is a binding',
        ],
        'Filament/Actions/QuickTaskAction.php' => [
            'constant' => 'whereRaw(\'1 = 0\') only, to keep a relationship query empty',
        ],
        'Filament/Resources/Companies/RelationManagers/DealsRelationManager.php' => [
            'constant' => 'constant orderByRaw expressions on the deals table',
        ],
        'Filament/Resources/CompanyTypes/Actions/CompanyTypeDeletionActions.php' => [
            'constant' => 'lower(name) = ? with the name as a binding',
        ],
        'Filament/Resources/CompanyTypes/Schemas/CompanyTypeForm.php' => [
            'constant' => 'lower(name) = ? with the name as a binding',
        ],
        'Filament/Resources/Deals/Pages/DealPipeline.php' => [
            'constant' => 'constant aggregate select (COALESCE(SUM(value), 0)) for the board column totals',
        ],
        'Filament/Resources/Deals/Schemas/DealDrawerInfolist.php' => [
            'constant' => 'constant orderByRaw on the due date',
        ],
        'Filament/Resources/Tasks/Schemas/TaskForm.php' => [
            'constant' => 'whereRaw(\'1 = 0\') only, to keep a relationship query empty',
        ],
        'Filament/Resources/Tasks/Tables/TasksTable.php' => [
            'constant' => 'constant orderByRaw CASE on the due date',
        ],
        'Filament/Support/OrganizationNameInput.php' => [
            'constant' => 'lower(trim(name)) = ? with the name as a binding',
        ],
        'Filament/Widgets/DealsOverviewWidget.php' => [
            'constant' => 'constant aggregate selects',
        ],
        'Models/Concerns/BelongsToOrganization.php' => [
            'constant' => 'whereRaw(\'false\') of the fail-closed tenant scope',
        ],
        'Models/Concerns/EnforcesUniqueCustomFieldValues.php' => [
            'constant' => 'advisory lock query with the lock key as a binding',
        ],
        'Services/Companies/CompanyMatcher.php' => [
            'constant' => 'lower(trim(name)) = ? with the name as a binding',
        ],
        'Services/Deals/DealForecast.php' => [
            'constant' => 'constant aggregate select (COUNT, COALESCE(SUM(value), 0)) for the forecast by close month',
        ],
        'Services/Reports/DashboardKpis.php' => [
            'constant' => 'constant conditional aggregate select (COUNT/SUM over CASE) for the dashboard KPIs, one UNION ALL part per period; statuses, ledger kind, period bounds and the period index are bindings',
        ],
        'Services/Reports/Receivables.php' => [
            'constant' => 'constant CASE bucket select (COUNT, COALESCE(SUM(amount - amount_paid), 0)) for the invoice aging; dates and status are bindings',
        ],
        'Services/Reports/TeamActivity.php' => [
            'constant' => 'constant grouped aggregate selects (COUNT/SUM over CASE) for the team leaderboard, one per source; statuses, period bounds and now are bindings',
        ],
        'Services/Segments/SegmentQueryBuilder.php' => [
            'constant' => 'whereRaw(\'1 = 0\') only, for a rule without usable conditions',
        ],
        'Services/Segments/SegmentUsage.php' => [
            'constant' => 'JSON containment with the pattern as a binding',
        ],
        'Support/CustomFields/OptionUsage.php' => [
            'constant' => 'JSON key and value are bindings',
        ],
        'Jobs/SyncSegmentMembership.php' => [
            'constant' => 'constant temp-table statements; ids and dates are bindings',
            'dynamic' => 'the INSERT interpolates the toSql() of the segment query builder and passes its bindings',
        ],
        'Services/Segments/Conditions/ComparesExpressions.php' => [
            'dynamic' => 'the expression is built by the condition compilers from constants and grammar-wrapped columns; values are bindings',
        ],
        'Services/Segments/Conditions/CustomFieldConditionCompiler.php' => [
            'dynamic' => 'the inlined JSON key is checked with an anchored identifier regex in apply(); values are bindings',
        ],
        'Jobs/ResyncContactSegments.php' => [
            'dynamic' => 'interpolates the toSql() of the segment query builder and an integer segment id; bindings passed',
        ],
        'Filament/Support/NullsLastSort.php' => [
            'dynamic' => 'grammar-wrapped column from code; direction whitelisted to asc/desc; subquery bindings passed',
        ],
        'Models/Segment.php' => [
            'dynamic' => 'qualified column name from code; the JSON pattern is a binding',
        ],
        'Models/Concerns/HasCustomFieldValues.php' => [
            'dynamic' => 'qualified column name from code; the custom field key and value are bindings',
        ],
    ];
}

/**
 * @return array<int, array{line: int, kind: string}> raw SQL calls of a PHP source, `constant` when the first argument is only string literals joined by dots, `dynamic` otherwise.
 *                                                    Matched: the `*Raw` query methods, `raw` and `getPdo` on any receiver, `new Expression`, and the database calls (`select`, `statement`, `unprepared`, …)
 *                                                    on the `DB` facade or straight after `connection(...)` / `getConnection()`. A connection held in a variable (`$connection->select(...)`) is not detected (REF-28).
 */
function rawSqlHitsInCode(string $code): array
{
    $methods = ['raw', 'getPdo', 'whereRaw', 'orWhereRaw', 'selectRaw', 'orderByRaw', 'havingRaw', 'orHavingRaw', 'groupByRaw', 'fromRaw', 'joinRaw'];
    $databaseCalls = ['raw', 'select', 'selectOne', 'scalar', 'insert', 'update', 'delete', 'statement', 'affectingStatement', 'unprepared'];
    $tokens = PhpToken::tokenize($code);
    $count = count($tokens);
    $hits = [];

    $next = function (int $from) use ($tokens, $count): int {
        $index = $from;
        while ($index < $count && $tokens[$index]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $index++;
        }

        return $index;
    };

    $previous = function (int $from) use ($tokens): int {
        $index = $from;
        while ($index > 0 && $tokens[$index]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $index--;
        }

        return $index;
    };

    $callNameBeforeParenthesis = function (int $closeIndex) use ($tokens, $previous): ?string {
        $depth = 0;

        for ($index = $closeIndex; $index >= 0; $index--) {
            $text = $tokens[$index]->text;

            if ($text === ')') {
                $depth++;
            }

            if ($text === '(' && --$depth === 0) {
                $name = $tokens[$previous($index - 1)];

                return $name->is(T_STRING) ? $name->text : null;
            }
        }

        return null;
    };

    $kindOfArgument = function (int $open) use ($tokens, $count): string {
        $depth = 0;
        $argument = [];

        for ($index = $open; $index < $count; $index++) {
            $text = $tokens[$index]->text;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            }

            if (in_array($text, [')', ']', '}'], true) && --$depth === 0) {
                break;
            }

            if ($text === ',' && $depth === 1) {
                break;
            }

            if ($index > $open) {
                $argument[] = $tokens[$index];
            }
        }

        $significant = array_values(array_filter($argument, fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));

        if ($significant === []) {
            return 'dynamic';
        }

        foreach ($significant as $position => $token) {
            $expected = $position % 2 === 0 ? T_CONSTANT_ENCAPSED_STRING : null;

            if ($expected !== null && ! $token->is($expected)) {
                return 'dynamic';
            }

            if ($expected === null && $token->text !== '.') {
                return 'dynamic';
            }
        }

        return count($significant) % 2 === 1 ? 'constant' : 'dynamic';
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $nameIndex = null;

        if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            $candidate = $next($i + 1);
            $isDatabaseCall = false;

            $before = $tokens[$previous($i - 1)];

            if ($token->is(T_DOUBLE_COLON)) {
                $isDatabaseCall = $before->text === 'DB' || str_ends_with($before->text, '\\DB');
            }

            if (! $token->is(T_DOUBLE_COLON) && $before->text === ')') {
                $isDatabaseCall = in_array($callNameBeforeParenthesis($previous($i - 1)), ['connection', 'getConnection'], true);
            }

            if ($tokens[$candidate]->is(T_STRING)
                && (in_array($tokens[$candidate]->text, $methods, true) || ($isDatabaseCall && in_array($tokens[$candidate]->text, $databaseCalls, true)))) {
                $nameIndex = $candidate;
            }
        }

        if ($token->is(T_NEW)) {
            $candidate = $next($i + 1);
            $text = $tokens[$candidate]->text;

            if ($text === 'Expression' || str_ends_with($text, '\\Expression')) {
                $nameIndex = $candidate;
            }
        }

        if ($nameIndex === null) {
            continue;
        }

        $open = $next($nameIndex + 1);

        if (($tokens[$open]->text ?? null) !== '(') {
            continue;
        }

        $hits[] = ['line' => $tokens[$nameIndex]->line, 'kind' => $kindOfArgument($open)];
    }

    return $hits;
}

/**
 * @return array<string, array<int, array{line: int, kind: string}>> hits of every file of `app/` that has a raw SQL call, by relative path
 */
function rawSqlHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        $found = rawSqlHitsInCode($file->getContents());

        if ($found !== []) {
            $hits[str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $found;
        }
    }

    ksort($hits);

    return $hits;
}

test('every raw SQL call is allow-listed with a reason for its kind', function () {
    $allowList = rawSqlAllowList();
    $problems = [];

    foreach (rawSqlHits() as $path => $hits) {
        foreach ($hits as $hit) {
            if (trim($allowList[$path][$hit['kind']] ?? '') === '') {
                $problems[] = "app/{$path}:{$hit['line']} → {$hit['kind']}";
            }
        }
    }

    expect($problems)->toBe([], 'Raw SQL outside the allow-list. Bind values with ? and validate inlined identifiers at the sink, then add the file with the reason:'."\n".implode("\n", $problems));
});

test('the raw SQL allow-list has no stale entry', function () {
    $hits = rawSqlHits();
    $stale = [];

    foreach (rawSqlAllowList() as $path => $kinds) {
        foreach ($kinds as $kind => $reason) {
            $found = collect($hits[$path] ?? [])->contains(fn (array $hit): bool => $hit['kind'] === $kind);

            if (! in_array($kind, ['constant', 'dynamic'], true) || trim($reason) === '' || ! $found) {
                $stale[] = "{$path} → {$kind}";
            }
        }
    }

    expect($stale)->toBe([], 'Allow-list entries without a matching raw call (or without a reason): remove them.');
});

/**
 * @return array<int, int> line numbers of the `use` statements that alias the DB facade
 */
function aliasedDatabaseFacadeLines(string $code): array
{
    $lines = [];

    foreach (explode("\n", $code) as $index => $line) {
        if (preg_match('/^\s*use\s[^;]*\bDB\s+as\s+\w+/', $line) === 1) {
            $lines[] = $index + 1;
        }
    }

    return $lines;
}

test('the DB facade is never aliased', function () {
    $aliased = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        foreach (aliasedDatabaseFacadeLines($file->getContents()) as $line) {
            $aliased[] = 'app/'.str_replace('\\', '/', $file->getRelativePathname()).':'.$line;
        }
    }

    expect($aliased)->toBe([], 'Do not alias the DB facade: the raw SQL scanner only recognises `DB::` (REF-28).'."\n".implode("\n", $aliased));
});

test('the alias scanner finds an aliased DB facade', function (string $code, array $lines) {
    expect(aliasedDatabaseFacadeLines($code))->toBe($lines);
})->with([
    'plain alias' => ["<?php\nuse Illuminate\\Support\\Facades\\DB as Database;", [2]],
    'group alias' => ["<?php\nuse Illuminate\\Support\\Facades\\{DB as Db, Log};", [2]],
    'no alias' => ["<?php\nuse Illuminate\\Support\\Facades\\DB;", []],
    'other class ending in DB' => ["<?php\nuse App\\Models\\DBLog as Log;", []],
]);

test('the raw SQL scanner classifies calls', function (string $code, array $kinds) {
    expect(array_column(rawSqlHitsInCode('<?php '.$code), 'kind'))->toBe($kinds);
})->with([
    'interpolated whereRaw' => ['$q->whereRaw("name = \'{$x}\'");', ['dynamic']],
    'variable orderByRaw' => ['$q->orderByRaw($sql);', ['dynamic']],
    'concatenated statement' => ['DB::statement(\'DROP TABLE x \'.$t);', ['dynamic']],
    'DB::raw interpolated' => ['DB::raw("count({$c})");', ['dynamic']],
    'new Expression variable' => ['new Expression($e);', ['dynamic']],
    'fully qualified Expression' => ['new \Illuminate\Database\Query\Expression($e);', ['dynamic']],
    'method call argument' => ['$q->selectRaw($this->sql());', ['dynamic']],
    'literal then variable' => ['$q->whereRaw(\'a = \'.$x, [1]);', ['dynamic']],
    'binding variable only' => ['$q->whereRaw(\'lower(name) = ?\', [$v]);', ['constant']],
    'two literals' => ['$q->selectRaw(\'a, \' . \'b\');', ['constant']],
    'plain double quotes' => ['$q->havingRaw("count(*) > 1");', ['constant']],
    'static call on a model' => ['Contact::selectRaw($x);', ['dynamic']],
    'fully qualified DB facade' => ['\Illuminate\Support\Facades\DB::select($sql);', ['dynamic']],
    'nullsafe call' => ['$q?->whereRaw($x);', ['dynamic']],
    'multi-line call' => ["\$q->whereRaw(\n    'a = ?',\n    [\$v],\n);", ['constant']],
    'several calls' => ['$q->whereRaw(\'a\')->orWhereRaw($b);', ['constant', 'dynamic']],
    'query raw' => ['$q->raw($sql);', ['dynamic']],
    'query builder raw literal' => ['DB::query()->raw(\'1 = 1\');', ['constant']],
    'nullsafe raw' => ['$q?->raw("a {$b}");', ['dynamic']],
    'connection select' => ['DB::connection()->select($sql);', ['dynamic']],
    'named connection statement' => ['DB::connection(\'pgsql\')->statement("DROP {$t}");', ['dynamic']],
    'model connection literal' => ['$model->getConnection()->select(\'select 1\');', ['constant']],
    'nullsafe connection' => ['$q?->getConnection()?->unprepared($sql);', ['dynamic']],
    'connection with a call argument' => ['DB::connection(config(\'database.default\'))->select($sql);', ['dynamic']],
    'facade pdo' => ['DB::getPdo();', ['dynamic']],
    'connection pdo' => ['$q->getConnection()->getPdo()->exec($sql);', ['dynamic']],
    'not detected: connection table' => ['DB::connection()->table(\'x\')->get();', []],
    'not detected: driver name' => ['$q->getConnection()->getDriverName();', []],
    'not detected: connection transaction' => ['DB::connection()->transaction(fn () => 1);', []],
    'not detected: other call' => ['$this->contacts()->select(\'name\');', []],
    'not detected: where' => ['$q->where(\'name\', $x);', []],
    'not detected: select' => ['$q->select(\'name\');', []],
    'not detected: table' => ['DB::table(\'x\')->get();', []],
    'not detected: transaction' => ['DB::transaction(fn () => 1);', []],
    'not detected: declaration' => ['function whereRaw($sql) {}', []],
]);
