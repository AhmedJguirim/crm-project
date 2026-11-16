<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a Livewire temporary upload carrying more than one file (SEC-53): the app only uploads one file per request,
 * and the per-user budget of `livewire-uploads` counts requests, so one file per request bounds the bytes a user can
 * write to `livewire-tmp/`.
 */
class LimitTemporaryUploadFiles
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (count(Arr::flatten($request->allFiles())) <= 1) {
            return $next($request);
        }

        $message = 'Upload one file at a time.';

        return response()->json(['message' => $message, 'errors' => ['files.0' => [$message]]], 422);
    }
}
