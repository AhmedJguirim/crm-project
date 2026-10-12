<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the failed rows file of an import. The link is signed and carries the importing user, so only that user can
 * use it, and it only takes a file name, never a path.
 */
class DownloadFailedImportRowsController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        abort_unless((int) $request->query('user') === auth()->id(), 403);

        $file = $request->query('file');

        if (! is_string($file) || preg_match('/^failed-[A-Za-z0-9]{40}\.csv$/', $file) !== 1) {
            abort(404);
        }

        $path = "contact-imports/{$file}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'The failed rows file no longer exists.');
        }

        return Storage::disk('local')->download($path, $file, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
