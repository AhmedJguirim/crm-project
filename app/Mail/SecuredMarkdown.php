<?php

namespace App\Mail;

use Illuminate\Mail\Markdown;
use Illuminate\Support\EncodedHtmlString;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Markdown renderer for every mail of the app. User text is interpolated through `{{ }}` and CommonMark would turn it into
 * links or images afterwards, so while a template renders each echoed value is neutralised: `[` is escaped, `<` and `>`
 * become entities and `\` becomes `&#92;` (a backslash typed by the user would otherwise cancel the `\` added before `[`).
 * It is the encoder of `Markdown::withSecuredEncoding()` plus the backslash, installed here because the framework
 * encoder can't be extended and replaces any encoder set earlier.
 */
class SecuredMarkdown extends Markdown
{
    /**
     * @param  string  $view
     * @param  array<string, mixed>  $data
     * @param  CssToInlineStyles|null  $inliner
     */
    public function render($view, array $data = [], $inliner = null)
    {
        $securedEncoding = static::$withSecuredEncoding;
        static::$withSecuredEncoding = false;

        EncodedHtmlString::encodeUsing(fn ($value): string => strtr((string) ($value ?? ''), [
            '\\' => '&#92;',
            '[' => '\[',
            '<' => '&lt;',
            '>' => '&gt;',
        ]));

        try {
            return parent::render($view, $data, $inliner);
        } finally {
            static::$withSecuredEncoding = $securedEncoding;
            EncodedHtmlString::flushState();
        }
    }
}
