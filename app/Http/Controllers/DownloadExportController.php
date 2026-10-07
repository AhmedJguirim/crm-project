<?php

namespace App\Http\Controllers;

use App\Enums\ExportFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads an export file. The link is signed and carries the user who asked for the export, so only that user can
 * use it, and it only takes a file name, never a path.
 */
class DownloadExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        abort_unless((int) $request->query('user') === auth()->id(), 403);

        $file = $request->query('file');

        if (! is_string($file) || preg_match('/^contacts-[A-Za-z0-9]{40}\.(xlsx|csv)$/', $file, $matches) !== 1) {
            abort(404);
        }

        $path = "exports/{$file}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'The export file no longer exists.');
        }

        $name = $request->query('name');

        if (! is_string($name) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name) !== 1) {
            $name = $file;
        }

        return Storage::disk('local')->download($path, $name, [
            'Content-Type' => ExportFormat::from($matches[1])->contentType(),
        ]);
    }
}
