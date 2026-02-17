<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadFailedImportRowsController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        $path = $request->query('path');

        if (! $path || ! str_starts_with($path, 'contact-imports/failed-')) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'The failed rows file no longer exists.');
        }

        $filename = basename($path);

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
