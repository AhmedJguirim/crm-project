<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

class StripNullBytes extends TransformsRequest
{
    /**
     * PostgreSQL can't store a NUL byte: a JSONB write fails and text is cut at it. Strips it from every incoming string;
     * Livewire updates are included (they are read from the cleaned JSON input).
     */
    protected function transform($key, $value): mixed
    {
        return is_string($value) ? str_replace("\0", '', $value) : $value;
    }
}
