<?php

namespace App\Support\Security;

/**
 * Reads the free and total space of the disk that holds a folder (SEC-47). Not final, so tests can mock it.
 */
class DiskSpace
{
    public const int BYTES_PER_GB = 1073741824;

    /**
     * @return array{free: float, total: float}|null
     */
    public function read(string $path): ?array
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return ['free' => $free, 'total' => $total];
    }
}
