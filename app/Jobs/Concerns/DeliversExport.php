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
use App\Support\Tenancy\TenantContext;
use App\Support\UserFiles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
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
 * the three hooks below (`exportFileExtension()` is an optional fourth).
 *
 * The export holds one slot of {@see ImportExportLimits} from the moment it is queued until it ends. The file is kept
 * and announced only while the user may still export from the organization: checked after the file is written, a user
 * removed, demoted or gone meanwhile gets no file and no notification.
 */
trait DeliversExport
{
    use HoldsImportExportSlot;

    /**
     * Just under the timeout of the `exports` Horizon supervisor, so a very long export fails cleanly before the
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

    /**
     * The extension of the stored file: the format, unless the export of that format is another kind of file (the
     * invoices CSV export is a ZIP).
     */
    protected function exportFileExtension(ExportFormat $format): string
    {
        return $format->value;
    }

    protected function prepareExportQueue(): void
    {
        $this->onQueue('exports');
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
        $extension = $this->exportFileExtension($format);
        $temporaryPath = TemporaryFile::reserve("{$prefix}-export-", $extension);

        try {
            $count = $this->writeExport($temporaryPath, $format);

            $path = UserFiles::exportPath($prefix, $this->organizationId, $this->userId, $extension);

            $stream = fopen($temporaryPath, 'rb');
            Storage::disk('local')->put($path, $stream);
            fclose($stream);
        } finally {
            @unlink($temporaryPath);
        }

        $user = User::find($this->userId);

        if (! $user || ! $this->userMayStillExport($user)) {
            Storage::disk('local')->delete($path);

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

    /**
     * Whether the user may still export this list from the organization of the export, read now, after the file was
     * written: a member removed, demoted or gone meanwhile must not keep it.
     */
    private function userMayStillExport(User $user): bool
    {
        $model = DownloadExportController::EXPORT_MODELS[$this->exportFilePrefix()] ?? null;

        return $model !== null && app(TenantContext::class)->run(
            $this->organizationId,
            fn (): bool => Gate::forUser($user)->allows('export', $model),
        );
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
