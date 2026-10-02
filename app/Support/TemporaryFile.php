<?php

namespace App\Support;

use RuntimeException;

class TemporaryFile
{
    /**
     * Reserves a unique, empty file in the system temp directory with the given extension, and returns its path.
     * The caller owns the file and must delete it.
     *
     * `tempnam()` creates the file itself, so appending the extension to its name would leave an empty file behind:
     * the file is renamed instead.
     */
    public static function reserve(string $prefix, string $extension): string
    {
        $basePath = tempnam(sys_get_temp_dir(), $prefix);

        if ($basePath === false) {
            throw new RuntimeException('Could not create a temporary file in ['.sys_get_temp_dir().'].');
        }

        $path = "{$basePath}.{$extension}";

        if (! rename($basePath, $path)) {
            @unlink($basePath);

            throw new RuntimeException("Could not rename the temporary file [{$basePath}] to [{$path}].");
        }

        return $path;
    }
}
