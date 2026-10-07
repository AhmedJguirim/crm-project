<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactExportWriter;
use App\Support\TemporaryFile;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the given contacts to a spreadsheet and tells the user when it is ready to download.
 *
 * It runs on the `imports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the contacts 500 at a time, in the order of the given ids.
 */
class ExportContactsJob implements ShouldQueue
{
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * Just under the timeout of the `imports` Horizon supervisor, so a very long export fails cleanly before the
     * worker is killed.
     */
    public int $timeout = 1740;

    public bool $failOnTimeout = true;

    public int $tries = 1;

    /**
     * @param  array<int, int>  $contactIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $contactIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->onQueue('imports');
        $this->onConnection(config('queue.long_running_connection'));
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new WithTenantContext($this->organizationId)];
    }

    public function handle(): void
    {
        $format = ExportFormat::from($this->format);
        $customFields = CustomField::forOrganization($this->organizationId)->orderBy('order')->get();
        $temporaryPath = TemporaryFile::reserve('contacts-export-', $format->value);

        try {
            $count = (new ContactExportWriter($customFields))->write($temporaryPath, $format, $this->chunks());

            $path = 'exports/contacts-'.Str::random(40).'.'.$format->value;

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

        $contacts = Number::format($count).' '.Str::plural('contact', $count);

        Notification::make()
            ->success()
            ->title('Export ready')
            ->body($contacts)
            ->actions([
                Action::make('download')
                    ->label('Download')
                    ->url(URL::temporarySignedRoute(
                        'exports.download',
                        now()->addDays(7),
                        ['file' => basename($path), 'user' => $this->userId, 'name' => $this->downloadName],
                    ))
                    ->openUrlInNewTab(),
            ])
            ->sendToDatabase($user);
    }

    public function failed(?Throwable $exception): void
    {
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

    /**
     * The contacts of the export, one chunk at a time, in the order of the ids. A contact that no longer exists is
     * skipped; trashed ones are exported.
     *
     * @return Generator<int, Collection<int, Contact>>
     */
    private function chunks(): Generator
    {
        $positions = array_flip($this->contactIds);

        foreach (array_chunk($this->contactIds, self::CHUNK_SIZE) as $ids) {
            yield Contact::query()
                ->forOrganization($this->organizationId)
                ->withTrashed()
                ->with(['tags', 'companies'])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Contact $contact): int => $positions[$contact->getKey()])
                ->values();
        }
    }
}
