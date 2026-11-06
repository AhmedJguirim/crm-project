<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Serves the public Privacy Policy and Terms of Service. The text lives only in `resources/markdown/privacy.md` and `terms.md`; it is rendered as Markdown with raw HTML stripped and unsafe links dropped.
 */
class LegalPageController extends Controller
{
    public function privacy(): View
    {
        return $this->show('privacy', 'Privacy Policy', 'How CRM collects, uses and protects personal data.');
    }

    public function terms(): View
    {
        return $this->show('terms', 'Terms of Service', 'The terms for using CRM, including how we process the data you store in it.');
    }

    /**
     * Turns trusted Markdown into HTML: raw HTML is removed and `javascript:`-style links are dropped.
     */
    public static function toHtml(string $markdown): HtmlString
    {
        return new HtmlString(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    private function show(string $document, string $title, string $description): View
    {
        return view('legal.show', [
            'title' => $title,
            'description' => $description,
            'canonical' => route($document),
            'body' => self::toHtml(File::get(resource_path("markdown/{$document}.md"))),
        ]);
    }
}
