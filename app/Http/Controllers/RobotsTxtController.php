<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves robots.txt: crawlers stay out of the app sections and are pointed at the sitemap. It is a route, not a static
 * file, because the `Sitemap:` line must be an absolute URL built from `APP_URL`.
 */
class RobotsTxtController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /app/',
            'Disallow: /app$',
            'Disallow: /contacts',
            'Disallow: /dashboard',
            'Disallow: /exports',
            'Disallow: /horizon',
            'Disallow: /invite',
            'Disallow: /invoices',
            'Disallow: /settings',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
