<?php

namespace App\Support;

use RuntimeException;

class TemporaryFile
{
    private static ?string $directory = null;

    /**
     * Makes every file reserved afterwards go to the given directory instead of the system one (tests). Pass null to
     * go back to the system temp directory.
     */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory;
    }

    /**
     * Reserves a unique, empty file in the system temp directory, or the one set with useDirectory(), with the given extension, and returns its path.
     * The caller owns the file and must delete it.
     *
     * `tempnam()` creates the file itself, so appending the extension to its name would leave an empty file behind:
     * the file is renamed instead.
     */
    public static function reserve(string $prefix, string $extension): string
    {
        $basePath = tempnam(self::directory(), $prefix);

        if ($basePath === false) {
            throw new RuntimeException('Could not create a temporary file in ['.self::directory().'].');
        }

        $path = "{$basePath}.{$extension}";

        if (! rename($basePath, $path)) {
            @unlink($basePath);

            throw new RuntimeException("Could not rename the temporary file [{$basePath}] to [{$path}].");
        }

        return $path;
    }

    private static function directory(): string
    {
        return self::$directory ?? sys_get_temp_dir();
    }
}
