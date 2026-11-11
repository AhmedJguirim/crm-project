<?php

/**
 * Parses the repository's .env.example into name => raw value pairs.
 *
 * @return array<string, string>
 */
function exampleEnvValues(): array
{
    $values = [];

    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $match) === 1) {
            $values[$match[1]] = $match[2];
        }
    }

    return $values;
}

test('the example env ships an empty application key', function () {
    expect(exampleEnvValues())->toHaveKey('APP_KEY')
        ->and(exampleEnvValues()['APP_KEY'])->toBe('');
});

test('the example env ships an empty database password', function () {
    expect(exampleEnvValues())->toHaveKey('DB_PASSWORD')
        ->and(exampleEnvValues()['DB_PASSWORD'])->toBe('');
});

test('no key, secret, password or token variable carries a value other than empty or null', function () {
    expect(file_get_contents(base_path('.env.example')))
        ->not->toMatch('/^[A-Z0-9_]*(KEYS?|SECRET|PASSWORD|TOKEN)=(?!\s*$|null\s*$)/m');
});

test('the committed key and password are gone from the example env', function () {
    $contents = file_get_contents(base_path('.env.example'));

    expect($contents)->not->toContain('base64:')
        ->and($contents)->not->toContain('Pg12300');
});

test('the secret pattern bites on a committed value', function () {
    $pattern = '/^[A-Z0-9_]*(KEYS?|SECRET|PASSWORD|TOKEN)=(?!\s*$|null\s*$)/m';

    expect("APP_KEY=base64:abc=\n")->toMatch($pattern)
        ->and("DB_PASSWORD=Pg12300\n")->toMatch($pattern)
        ->and("API_TOKEN=x\n")->toMatch($pattern)
        ->and("APP_PREVIOUS_KEYS=abc\n")->toMatch($pattern)
        ->and("APP_KEY=\nREDIS_PASSWORD=null\nAWS_SECRET_ACCESS_KEY=\n")->not->toMatch($pattern);
});

test('the application key line appears once so key:generate can fill it', function () {
    expect(preg_match_all('/^APP_KEY=$/m', file_get_contents(base_path('.env.example'))))->toBe(1)
        ->and(substr_count(file_get_contents(base_path('.env.example')), 'APP_KEY'))->toBe(1);
});
