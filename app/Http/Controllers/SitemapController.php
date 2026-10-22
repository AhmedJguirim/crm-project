<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves sitemap.xml: the landing page is the only public page. The `<loc>` is built from `APP_URL`, like the canonical.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $home = htmlspecialchars(url('/'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            '    <url>',
            "        <loc>{$home}</loc>",
            '    </url>',
            '</urlset>',
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
