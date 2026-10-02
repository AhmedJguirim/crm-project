<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when an uploaded import file has an unsupported type or cannot be read as a spreadsheet.
 */
class UnreadableImportFileException extends RuntimeException
{
    public static function unsupportedType(string $extension): self
    {
        return new self("Unsupported file type: .{$extension}");
    }

    public static function unreadable(Throwable $previous): self
    {
        return new self('The import file could not be read.', previous: $previous);
    }
}
