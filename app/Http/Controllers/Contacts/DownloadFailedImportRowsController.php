<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\Security\SecurityLog;
use App\Support\Tenancy\TenantContext;
use App\Support\UserFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the failed rows file of an import. The link is signed and carries the importing user and the organization
 * of the import, so only that user can use it, only while they may still import in that organization, and it only takes
 * a file name, never a path.
 */
class DownloadFailedImportRowsController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        abort_unless((int) $request->query('user') === auth()->id(), 403);

        $file = $request->query('file');

        if (! is_string($file) || preg_match(UserFiles::FAILED_ROWS_NAME_PATTERN, $file) !== 1) {
            abort(404);
        }

        abort_unless(
            $this->mayStillImport($request->query('organization')),
            403,
            'You can no longer import in this organization, so this file can\'t be downloaded.',
        );

        $path = "contact-imports/{$file}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'The failed rows file no longer exists.');
        }

        SecurityLog::info('data.import_failed_rows_downloaded', auth()->user(), [
            'file_organization_id' => (int) $request->query('organization'),
        ]);

        return Storage::disk('local')->download($path, $file, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Whether the link names a valid organization and the logged-in user may still import in it. Contact and company
     * imports write to the same folder; both policies give `import` to the same roles (`$manageAbilities`,
     * `ContactPolicy.php:12`, `CompanyPolicy.php:12`).
     */
    private function mayStillImport(mixed $organization): bool
    {
        if (! is_string($organization) || ! ctype_digit($organization) || (int) $organization < 1) {
            return false;
        }

        return app(TenantContext::class)->run((int) $organization, fn (): bool => Gate::allows('import', Contact::class));
    }
}
