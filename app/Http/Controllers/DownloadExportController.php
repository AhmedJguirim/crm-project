<?php

namespace App\Http\Controllers;

use App\Enums\ExportFormat;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Task;
use App\Support\Security\SecurityLog;
use App\Support\Tenancy\TenantContext;
use App\Support\UserFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads an export file. The link is signed and carries the user who asked for the export and the organization it
 * was made in, so only that user can use it, only while they may still export from that organization, and it only takes
 * a file name, never a path.
 */
class DownloadExportController extends Controller
{
    /**
     * The file prefix of each export (see `DeliversExport::exportFilePrefix()`) and the model whose policy's `export`
     * ability the download checks. A prefix that is not here is refused.
     *
     * @var array<string, class-string<Model>>
     */
    public const EXPORT_MODELS = [
        'contacts' => Contact::class,
        'companies' => Company::class,
        'deals' => Deal::class,
        'tasks' => Task::class,
        'activities' => Activity::class,
        'invoices' => Invoice::class,
    ];

    /**
     * The prefixes of the exports that are a ZIP; a `.zip` file with any other prefix is refused.
     *
     * @var array<int, string>
     */
    private const ZIP_PREFIXES = ['invoices'];

    public function __invoke(Request $request): StreamedResponse|Response
    {
        abort_unless((int) $request->query('user') === auth()->id(), 403);

        $file = $request->query('file');

        if (! is_string($file) || preg_match(UserFiles::EXPORT_NAME_PATTERN, $file, $matches) !== 1) {
            abort(404);
        }

        $model = self::EXPORT_MODELS[$matches[1]] ?? null;

        if ($model === null || ($matches[2] === 'zip' && ! in_array($matches[1], self::ZIP_PREFIXES, true))) {
            abort(404);
        }

        abort_unless($this->mayStillExport($request->query('organization'), $model), 403);

        $path = "exports/{$file}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'The export file no longer exists.');
        }

        $name = $request->query('name');

        if (! is_string($name) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name) !== 1) {
            $name = $file;
        }

        SecurityLog::info('data.export_downloaded', auth()->user(), [
            'list' => $matches[1],
            'format' => $matches[2],
            'file_organization_id' => (int) $request->query('organization'),
        ]);

        return Storage::disk('local')->download($path, $name, [
            'Content-Type' => $matches[2] === 'zip' ? 'application/zip' : ExportFormat::from($matches[2])->contentType(),
        ]);
    }

    /**
     * Whether the link names a valid organization and the logged-in user may still export the records of the file's
     * model in it.
     *
     * @param  class-string<Model>  $model
     */
    private function mayStillExport(mixed $organization, string $model): bool
    {
        if (! is_string($organization) || ! ctype_digit($organization) || (int) $organization < 1) {
            return false;
        }

        return app(TenantContext::class)->run((int) $organization, fn (): bool => Gate::allows('export', $model));
    }
}
