<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when an uploaded import file has an unsupported type or cannot be read as a spreadsheet.
 */
class UnreadableImportFileException extends RuntimeException
{
    /** What the user is told when this is the whole reason the import failed, instead of the generic "couldn't read" line. */
    public ?string $userMessage = null;

    public static function duplicateColumn(string $column): self
    {
        $exception = new self("Two columns are named \"{$column}\".");
        $exception->userMessage = "Two columns are named \"{$column}\". Keep only one and import the file again.";

        return $exception;
    }

    public static function unsupportedType(string $extension): self
    {
        return new self("Unsupported file type: .{$extension}");
    }

    public static function unreadable(Throwable $previous): self
    {
        return new self('The import file could not be read.', previous: $previous);
    }
}
