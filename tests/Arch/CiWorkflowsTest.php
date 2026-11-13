<?php

/**
 * @return array<string, array<int, string>> workflow file name => its lines
 */
function archWorkflowLines(): array
{
    $files = glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') ?: [];
    $workflows = [];

    foreach ($files as $file) {
        $workflows[basename($file)] = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    }

    return $workflows;
}

/**
 * @param  array<int, string>  $lines
 * @return array<int, string> the lines that are not blank and not a YAML comment, keyed by their line index
 */
function archCodeLines(array $lines): array
{
    return array_filter($lines, fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#'));
}

/**
 * @param  array<int, string>  $lines
 * @return array<int, string> the lines of the step that holds the line at $index (from its "- " line to the next sibling step)
 */
function archStepLines(array $lines, int $index): array
{
    $start = $index;

    while ($start > 0 && preg_match('/^\s*-\s/', $lines[$start]) !== 1) {
        $start--;
    }

    $indent = strlen($lines[$start]) - strlen(ltrim($lines[$start]));
    $step = [$lines[$start]];

    for ($i = $start + 1; $i < count($lines); $i++) {
        $line = $lines[$i];

        if (trim($line) !== '' && ! str_starts_with(ltrim($line), '#')) {
            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent < $indent || ($lineIndent === $indent && str_starts_with(ltrim($line), '- '))) {
                break;
            }
        }

        $step[] = $line;
    }

    return $step;
}

/**
 * CI hardening (SEC-41): a read-only token, actions pinned to a commit and no token left in .git/config.
 */
test('the workflow folder holds the three known workflows', function () {
    expect(array_keys(archWorkflowLines()))->toContain('lint.yml', 'security-audit.yml', 'tests.yml')
        ->and(count(archWorkflowLines()))->toBeGreaterThanOrEqual(3);
});

test('every workflow declares exactly a read-only contents token at the top level', function (string $file) {
    $lines = archWorkflowLines()[$file] ?? [];
    $declared = false;
    $onlyContents = false;

    foreach ($lines as $index => $line) {
        if (preg_match('/^permissions:\s*$/', $line) !== 1 || preg_match('/^  contents: read\s*$/', $lines[$index + 1] ?? '') !== 1) {
            continue;
        }

        $declared = true;
        $next = archCodeLines(array_slice($lines, $index + 2, null, true));
        $following = $next === [] ? '' : reset($next);
        $onlyContents = $following === '' || preg_match('/^\S/', $following) === 1;
    }

    expect($declared)->toBeTrue("{$file}: no top-level `permissions:` block with `  contents: read`")
        ->and($onlyContents)->toBeTrue("{$file}: the top-level `permissions:` block holds more than `contents: read`");
})->with(['lint.yml', 'security-audit.yml', 'tests.yml']);

test('no workflow declares a job-level permissions block', function () {
    $offenders = [];

    foreach (archWorkflowLines() as $file => $lines) {
        foreach (archCodeLines($lines) as $line) {
            if (preg_match('/^\s+permissions:/', $line) === 1) {
                $offenders[] = $file.': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], 'Only the top-level read-only block is allowed (SEC-41): '.implode(' || ', $offenders));
});

test('no workflow grants a write scope or read-all', function () {
    $offenders = [];

    foreach (archWorkflowLines() as $file => $lines) {
        foreach (archCodeLines($lines) as $line) {
            if (preg_match('/:\s*write(-all)?\s*$/', $line) === 1 || preg_match('/^\s*permissions:\s*(write|read)-all/', $line) === 1) {
                $offenders[] = $file.': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], 'No write scope, write-all or read-all (SEC-41): '.implode(' || ', $offenders));
});

test('every action in every workflow is pinned to a full commit sha with its tag noted', function () {
    $offenders = [];
    $seen = 0;

    foreach (archWorkflowLines() as $file => $lines) {
        foreach (archCodeLines($lines) as $line) {
            if (preg_match('/^\s*-?\s*uses:/', $line) !== 1) {
                continue;
            }

            $seen++;

            if (preg_match('/uses:\s*[\w.-]+\/[\w.-]+(\/[\w.\/-]+)?@[0-9a-f]{40}\s+#\s+\S+\s*$/', $line) !== 1) {
                $offenders[] = $file.': '.trim($line);
            }
        }
    }

    expect($seen)->toBeGreaterThan(0)
        ->and($offenders)->toBe([], 'Pin each action to a 40-character commit sha followed by "# tag" (SEC-41): '.implode(' || ', $offenders));
});

test('checkout never leaves the token in .git/config', function () {
    $offenders = [];
    $seen = 0;

    foreach (archWorkflowLines() as $file => $lines) {
        foreach (archCodeLines($lines) as $index => $line) {
            if (preg_match('/uses:\s*actions\/checkout@/', $line) !== 1) {
                continue;
            }

            $seen++;

            $step = archStepLines($lines, $index);
            $declared = array_filter($step, fn (string $stepLine): bool => preg_match('/^\s*persist-credentials:\s*false\s*$/', $stepLine) === 1);

            if ($declared === []) {
                $offenders[] = $file.': '.trim($line);
            }
        }
    }

    expect($seen)->toBeGreaterThan(0)
        ->and($offenders)->toBe([], 'Add `persist-credentials: false` to checkout (SEC-41): '.implode(' || ', $offenders));
});

test('the step scanner stops at the next step', function () {
    $lines = [
        '    steps:',
        '      - name: Checkout',
        '        uses: actions/checkout@abc',
        '      - name: Other',
        '        with:',
        '          persist-credentials: false',
    ];

    expect(archStepLines($lines, 2))->toBe(['      - name: Checkout', '        uses: actions/checkout@abc']);
});

test('dependabot covers composer, npm and github actions on master', function () {
    $path = dirname(__DIR__, 2).'/.github/dependabot.yml';
    $content = is_file($path) ? (string) file_get_contents($path) : '';

    expect($content)->toContain('package-ecosystem: "composer"')
        ->toContain('package-ecosystem: "npm"')
        ->toContain('package-ecosystem: "github-actions"')
        ->and(substr_count($content, 'target-branch: "master"'))->toBe(3);
});
