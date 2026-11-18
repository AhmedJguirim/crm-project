<?php

namespace App\Support\Security;

/**
 * What one account may export in a day before the daily owner alert fires (SEC-60): the number of exports queued, the
 * rows in them and the export files downloaded. An account is above a limit only when it is strictly over it.
 */
final readonly class ExportAlertLimits
{
    public function __construct(
        public int $exports,
        public int $rows,
        public int $downloads,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            max(0, (int) config('security.owner_alerts.exports_per_user_threshold')),
            max(0, (int) config('security.owner_alerts.exported_rows_per_user_threshold')),
            max(0, (int) config('security.owner_alerts.downloads_per_user_threshold')),
        );
    }
}
