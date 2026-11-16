<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The files stored for one user in one organization (exports, failed-rows files): their names carry both ids, so they
 * can be deleted when the user leaves the organization or deletes their account.
 */
final class UserFiles
{
    public const EXPORT_NAME_PATTERN = '/^([a-z]+)-o[1-9][0-9]*-u[1-9][0-9]*-[A-Za-z0-9]{40}\.(xlsx|csv)\z/';

    public const FAILED_ROWS_NAME_PATTERN = '/^failed-o[1-9][0-9]*-u[1-9][0-9]*-[A-Za-z0-9]{40}\.csv\z/';

    public static function exportPath(string $prefix, int $organizationId, int $userId, string $extension): string
    {
        return 'exports/'.$prefix.'-o'.$organizationId.'-u'.$userId.'-'.Str::random(40).'.'.$extension;
    }

    public static function failedRowsPath(int $organizationId, int $userId): string
    {
        return 'contact-imports/failed-o'.$organizationId.'-u'.$userId.'-'.Str::random(40).'.csv';
    }

    /**
     * Deletes the files the user has in one organization and returns how many were deleted.
     */
    public static function deleteForMember(int $userId, int $organizationId): int
    {
        return self::deleteMatching('o'.$organizationId, $userId);
    }

    /**
     * Deletes the files the user has in every organization and returns how many were deleted.
     */
    public static function deleteForUser(int $userId): int
    {
        return self::deleteMatching('o[1-9][0-9]*', $userId);
    }

    private static function deleteMatching(string $organizationPart, int $userId): int
    {
        $disk = Storage::disk('local');
        $ids = $organizationPart.'-u'.$userId.'-[A-Za-z0-9]{40}';

        $patterns = [
            'exports' => '/^[a-z]+-'.$ids.'\.(?:xlsx|csv)\z/',
            'contact-imports' => '/^failed-'.$ids.'\.csv\z/',
        ];

        $deleted = 0;

        foreach ($patterns as $directory => $pattern) {
            foreach ($disk->files($directory) as $file) {
                if (preg_match($pattern, basename($file)) !== 1) {
                    continue;
                }

                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
