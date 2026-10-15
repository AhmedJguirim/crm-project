<?php

use App\Services\Imports\ImportFileIsNotBinary;
use Illuminate\Http\UploadedFile;

/**
 * @return list<string>
 */
function binaryRuleMessages(string $name, string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'bin-rule-');
    file_put_contents($path, $content);

    $messages = [];

    try {
        (new ImportFileIsNotBinary)->validate('file', new UploadedFile($path, $name, null, null, true), function (string $message) use (&$messages) {
            $messages[] = $message;
        });
    } finally {
        unlink($path);
    }

    return $messages;
}

test('the rule refuses a .csv or .txt whose content is not text, whatever the case of the extension', function (string $name, string $content) {
    expect(binaryRuleMessages($name, $content))->toBe(['This file contains invalid characters (binary data). Re-save it as CSV UTF-8 or .xlsx.']);
})->with([
    'nul in a csv' => ['nul.csv', "name,email\nAnn Lee,ann\0@x.test\n"],
    'nul in a txt' => ['nul.txt', "name,email\nAnn Lee,ann\0@x.test\n"],
    'upper-case extension' => ['NUL.CSV', "name,email\nAnn Lee,ann\0@x.test\n"],
]);

test('the rule lets real text CSV files through', function (string $content) {
    expect(binaryRuleMessages('ok.csv', $content))->toBe([]);
})->with([
    'comma' => ["name,email\nAnn Lee,ann@x.test\n"],
    'semicolon' => ["name;email\nAnn Lee;ann@x.test\n"],
]);

test('the rule leaves other file names to the other rules', function () {
    expect(binaryRuleMessages('nul.xlsx', "name,email\nAnn Lee,ann\0@x.test\n"))->toBe([])
        ->and(binaryRuleMessages('nul.pdf', "name,email\nAnn Lee,ann\0@x.test\n"))->toBe([]);
});

test('a value that is not an uploaded file passes the rule', function () {
    $messages = [];

    (new ImportFileIsNotBinary)->validate('file', 'contact-imports/x.csv', function (string $message) use (&$messages) {
        $messages[] = $message;
    });

    expect($messages)->toBe([]);
});
