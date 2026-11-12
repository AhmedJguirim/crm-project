<?php

namespace App\Jobs\Concerns;

use App\Enums\ExportFormat;
use App\Http\Controllers\DownloadExportController;
use App\Jobs\Middleware\ReleasesImportExportSlot;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\User;
use App\Support\NotificationText;
use App\Support\TemporaryFile;
use App\Support\Tenancy\ImportExportLimits;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * What every export job does around building the file: the queue and timeout settings, the tenant context, the
 * temporary file moved to the `exports/` folder of the local disk, the signed "Export ready" notification and the
 * "Export failed" one. The signed link carries the user and the organization, so the download can check that the user
 * may still export from that organization. The job using it must have the `$organizationId`, `$userId`, `$format` (a value of
 * {@see ExportFormat}) and `$downloadName` properties, call `prepareExportQueue()` in its constructor and implement
 * the three hooks below.
 *
 * The export holds one slot of {@see ImportExportLimits} from the moment it is queued until it ends.
 */
trait DeliversExport
{
    use HoldsImportExportSlot;

    /**
     * Just under the timeout of the `imports` Horizon supervisor, so a very long export fails cleanly before the
     * worker is killed.
     */
    public int $timeout = 1740;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    /**
     * The start of the stored file name, e.g. `contacts`; it must be a key of
     * {@see DownloadExportController::EXPORT_MODELS}, or the download refuses the file.
     */
    abstract protected function exportFilePrefix(): string;

    /** What is counted in the notification, singular, e.g. `contact`. */
    abstract protected function exportNoun(): string;

    /**
     * Builds the writer and writes the file.
     *
     * @return int The number of records written.
     */
    abstract protected function writeExport(string $temporaryPath, ExportFormat $format): int;

    protected function prepareExportQueue(): void
    {
        $this->onQueue('imports');
        $this->onConnection(config('queue.long_running_connection'));
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ReleasesImportExportSlot, new WithTenantContext($this->organizationId)];
    }

    public function handle(): void
    {
        $format = ExportFormat::from($this->format);
        $prefix = $this->exportFilePrefix();
        $temporaryPath = TemporaryFile::reserve("{$prefix}-export-", $format->value);

        try {
            $count = $this->writeExport($temporaryPath, $format);

            $path = "exports/{$prefix}-".Str::random(40).".{$format->value}";

            $stream = fopen($temporaryPath, 'rb');
            Storage::disk('local')->put($path, $stream);
            fclose($stream);
        } finally {
            @unlink($temporaryPath);
        }

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->success()
            ->title('Export ready')
            ->body(NotificationText::plain(Number::format($count).' '.Str::plural($this->exportNoun(), $count)))
            ->actions([
                Action::make('download')
                    ->label('Download')
                    ->url(URL::temporarySignedRoute(
                        'exports.download',
                        now()->addDays(7),
                        [
                            'file' => basename($path),
                            'user' => $this->userId,
                            'organization' => $this->organizationId,
                            'name' => $this->downloadName,
                        ],
                    ))
                    ->openUrlInNewTab(),
            ])
            ->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
        $this->releaseImportExportSlot();

        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Export failed')
            ->body('Something went wrong while building the file. Try again, or contact support if it keeps failing.')
            ->sendToDatabase($user);
    }
}
