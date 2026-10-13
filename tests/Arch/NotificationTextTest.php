<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<int, int> line numbers of title/body calls whose argument has a variable and does not start with NotificationText::plain(
 */
function notificationTextHitsInCode(string $code): array
{
    $names = ['title', 'body', 'successNotificationTitle', 'failureNotificationTitle'];
    $tokens = PhpToken::tokenize($code);
    $count = count($tokens);
    $hits = [];

    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            continue;
        }
        $j = $i + 1;
        while ($tokens[$j]->is(T_WHITESPACE)) {
            $j++;
        }
        if (! $tokens[$j]->is(T_STRING) || ! in_array($tokens[$j]->text, $names, true)) {
            continue;
        }
        $k = $j + 1;
        while ($tokens[$k]->is(T_WHITESPACE)) {
            $k++;
        }
        if ($tokens[$k]->text !== '(') {
            continue;
        }
        $depth = 0;
        $argument = [];
        for ($m = $k; $m < $count; $m++) {
            $text = $tokens[$m]->text;
            if ($text === '(') {
                $depth++;
            }
            if ($text === ')' && --$depth === 0) {
                break;
            }
            if ($m > $k) {
                $argument[] = $tokens[$m];
            }
        }
        $hasVariable = array_filter($argument, fn (PhpToken $token): bool => $token->is([T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) !== [];
        $significant = array_values(array_filter($argument, fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $isWrapped = ($significant[0]->text ?? null) === 'NotificationText' && ($significant[1]->text ?? null) === '::' && ($significant[2]->text ?? null) === 'plain';
        if ($hasVariable && ! $isWrapped) {
            $hits[] = $tokens[$j]->line;
        }
    }

    return $hits;
}

/**
 * @return array<int, string> "app/relative/path.php:line" of every unwrapped notification title or body
 */
function notificationTextHits(): array
{
    $hits = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        foreach (notificationTextHitsInCode($file->getContents()) as $line) {
            $hits[] = 'app/'.$file->getRelativePathname().':'.$line;
        }
    }

    sort($hits);

    return $hits;
}

test('every notification title and body with a variable goes through NotificationText::plain', function () {
    expect(notificationTextHits())->toBe([]);
});

test('the notification text scanner finds unwrapped arguments', function (string $code, array $hits) {
    expect(notificationTextHitsInCode('<?php '.$code))->toBe($hits);
})->with([
    'interpolated title' => ['Notification::make()->title("Hi {$name}");', [1]],
    'plain variable body' => ['Notification::make()->body($message);', [1]],
    'multi-line ternary' => ["Notification::make()\n->title(\$a\n? 'x'\n: 'y');", [2]],
    'no variable' => ['Notification::make()->title(\'Saved\')->body(__(\'Done\'));', []],
    'wrapped' => ['Notification::make()->title(NotificationText::plain("Hi {$name}"));', []],
    'success notification title' => ['$action->successNotificationTitle("Deleted {$record->name}");', [1]],
]);
