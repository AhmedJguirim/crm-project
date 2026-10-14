<?php

namespace Tests\Feature\Security;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Hostile inputs and helpers shared by the security tests.
 */
final class SecurityPayloads
{
    /**
     * A NUL byte inside a value. Only the import tests use it: `Livewire::test()` skips the `StripNullBytes` middleware,
     * and PostgreSQL refuses a raw NUL, which the real app never lets through.
     */
    public const string NUL = "x\0y";

    /**
     * @var array<int, string> fragments that must never appear in the SQL text of a query
     */
    private const array SQL_MARKERS = ['pg_sleep', 'DROP TABLE', 'OR 1=1', "OR '1'='1", 'OR true', 'select 1;'];

    /**
     * @var array<int, string> fragments that must never appear as live markup in rendered HTML
     */
    private const array HTML_MARKERS = ['<script>alert(1)', '<img src=x onerror=alert(2)', '<svg onload=alert(3)', '<a href="https://evil.example"', 'href="https://evil.example"', 'href="javascript:'];

    /**
     * SQL injection payloads, keyed by a readable name, as a Pest dataset.
     *
     * @return array<string, array{0: string}>
     */
    public static function sql(): array
    {
        return [
            'or 1=1' => ["' OR '1'='1"],
            'comment' => ["x' OR 1=1 --"],
            'drop table' => ["'; DROP TABLE contacts; --"],
            'paren' => ["x') OR true --"],
            'pg_sleep' => ["x'||pg_sleep(1)||'"],
            'dollar quote' => ['$$ OR 1=1 $$'],
            'backslash' => ['\\'],
            'double quote' => ['"; select 1; --'],
        ];
    }

    /**
     * The SQL text of every query executed while `$run` runs, without bindings.
     *
     * @param  Closure(): mixed  $run
     * @return array<int, string>
     */
    public static function queriesDuring(Closure $run): array
    {
        $queries = [];
        $listening = true;

        DB::listen(function (QueryExecuted $query) use (&$queries, &$listening): void {
            if ($listening) {
                $queries[] = $query->sql;
            }
        });

        try {
            $run();
        } finally {
            $listening = false;
        }

        return $queries;
    }

    /**
     * Fails when the payload itself, or a marker of an injected statement, is part of the SQL text. A payload shorter
     * than four characters is not searched for: a lone backslash legitimately appears in compiled regular expressions.
     *
     * @param  array<int, string>  $sql
     */
    public static function assertSqlIsClean(array $sql, string $payload): void
    {
        foreach ($sql as $statement) {
            if (mb_strlen($payload) >= 4 && str_contains($statement, $payload)) {
                test()->fail("The payload [{$payload}] was inlined into SQL: {$statement}");
            }

            foreach (self::SQL_MARKERS as $marker) {
                if (str_contains($statement, $marker)) {
                    test()->fail("The marker [{$marker}] appears in SQL: {$statement}");
                }
            }
        }

        expect($sql)->toBeArray();
    }

    /**
     * XSS payloads, keyed by a readable name, as a Pest dataset.
     *
     * @return array<string, array{0: string}>
     */
    public static function xss(): array
    {
        return [
            'script' => ['<script>alert(1)</script>'],
            'img onerror' => ['<img src=x onerror=alert(2)>'],
            'attribute breakout' => ['"><svg onload=alert(3)>'],
            'styled link' => ['<a href="https://evil.example" style="position:fixed;inset:0">x</a>'],
            'markdown link' => ['[x](https://evil.example)'],
        ];
    }

    /**
     * Fails when rendered HTML holds live markup from a payload, or when the payload is not shown as text: if it were
     * raw markup, `strip_tags` would remove it, so the second check also fails on an unescaped sink and on an empty page.
     */
    public static function assertHtmlIsInert(string $html, string $payload): void
    {
        foreach (self::HTML_MARKERS as $marker) {
            expect($html)->not->toContain($marker);
        }

        expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->toContain($payload);
    }
}
