<?php

use Symfony\Component\Finder\Finder;

/**
 * Every form field whose state is a record id must be built with RecordKeyField (BUG-31), which guards the state
 * against a forged non-integer value. This test scans app/Filament for a `Select` / `Hidden` named `*_id`, and for a
 * `Select` whose chain reaches `->relationship(`.
 */
function recordKeyFieldLinePattern(): string
{
    return '/(?<![\w\\\\])(Select|Hidden)::make\(\s*[\'"]\w*_id[\'"]/';
}

function recordKeyFieldRelationshipPattern(): string
{
    return '/(?<![\w\\\\])Select::make\((?:(?!::make\(|RecordKeyField::).)*?->relationship\(/s';
}

/**
 * @return array<int, string> "relative/path.php:line" for every hit
 */
function recordKeyFieldHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Filament')->name('*.php') as $file) {
        $content = $file->getContents();
        $path = 'app/Filament/'.str_replace('\\', '/', $file->getRelativePathname());

        foreach (explode("\n", $content) as $index => $line) {
            if (preg_match(recordKeyFieldLinePattern(), $line) === 1) {
                $hits[] = $path.':'.($index + 1);
            }
        }

        preg_match_all(recordKeyFieldRelationshipPattern(), $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $offset]) {
            $hits[] = $path.':'.(substr_count(substr($content, 0, $offset), "\n") + 1);
        }
    }

    return $hits;
}

test('no record-id field is built without RecordKeyField', function () {
    $this->assertSame([], recordKeyFieldHits(), 'Record-id fields must be built with App\Filament\Support\RecordKeyField (BUG-31).');
});

test('the record-id patterns match what they should', function (string $source, bool $line, bool $relationship) {
    expect(preg_match(recordKeyFieldLinePattern(), $source) === 1)->toBe($line)
        ->and(preg_match(recordKeyFieldRelationshipPattern(), $source) === 1)->toBe($relationship);
})->with([
    'select named _id' => ["Select::make('contact_id')", true, false],
    'hidden named _id' => ["Hidden::make('contact_id')", true, false],
    'select filter' => ["SelectFilter::make('contact_id')", false, false],
    'timezone select' => ['OrganizationTimezoneSelect::make()', false, false],
    'select not an id' => ["Select::make('stage')", false, false],
    'the factory' => ["RecordKeyField::select('contact_id')", false, false],
    'select with a relationship' => ["Select::make('tags')\n    ->multiple()\n    ->relationship('tags', 'name')", false, true],
    'relationship of another component' => ["Select::make('stage')->options(X::class),\nTextInput::make('t')->relationship('x')", false, false],
    'relationship of a record-id field' => ["Select::make('lead_source')->options(X::class),\nRecordKeyField::multiSelect('tags')->relationship('tags', 'name')", false, false],
    'select filter with a relationship' => ["SelectFilter::make('tags')->relationship('tags', 'name')", false, false],
]);
