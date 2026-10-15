<?php

namespace App\Services\Imports;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Says why a .csv / .txt upload is refused when its content is not text: finfo calls any buffer that contains a NUL byte `application/octet-stream`, so the `mimetypes` rule alone told the user the file "is not of type text/csv" (BUG-47). Placed before `acceptedFileTypes()` so this message is the first error; every other file is left to the other rules.
 */
final class ImportFileIsNotBinary implements ValidationRule
{
    public const MESSAGE = 'This file contains invalid characters (binary data). Re-save it as CSV UTF-8 or .xlsx.';

    private const NON_TEXT_TYPES_ALLOWED = ['application/csv', 'application/vnd.ms-excel'];

    private const TEXT_EXTENSIONS = ['csv', 'txt'];

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if (! in_array(strtolower($value->getClientOriginalExtension()), self::TEXT_EXTENSIONS, true)) {
            return;
        }

        $type = (string) $value->getMimeType();

        if (str_starts_with($type, 'text/') || in_array($type, self::NON_TEXT_TYPES_ALLOWED, true)) {
            return;
        }

        $fail(self::MESSAGE);
    }
}
