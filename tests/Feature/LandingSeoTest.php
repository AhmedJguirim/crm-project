<?php

use App\Http\Controllers\SitemapController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @return array<string, mixed>
 */
function landingStructuredData(string $content): array
{
    expect(preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $content, $matches))->toBe(1);

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
}

function landingExpectedStructuredData(): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'CRM',
        'description' => "Keep every contact and company organized, move deals through your pipeline, follow up on time and see what you're owed.",
        'url' => url('/'),
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
    ];
}

test('a guest gets exactly one SoftwareApplication JSON-LD block', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect(substr_count($content, '<script type="application/ld+json">'))->toBe(1);

    $data = landingStructuredData($content);

    expect($data)->toBe(landingExpectedStructuredData())
        ->not->toHaveKeys(['offers', 'aggregateRating', 'review']);

    expect(preg_match('#<link rel="canonical" href="([^"]*)"#', $content, $canonical))->toBe(1);
    expect($data['url'])->toBe($canonical[1]);
});

test('a logged-in user gets the same JSON-LD block', function () {
    $guest = landingStructuredData($this->get('/')->getContent());

    $user = User::factory()->withPersonalOrganization()->create();
    $content = $this->actingAs($user)->get('/')->assertOk()->getContent();

    expect(landingStructuredData($content))->toBe($guest);
});

test('robots.txt keeps crawlers out of the app and points at the sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8');

    $expected = implode("\n", [
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
        '',
    ]);

    expect($response->getContent())->toBe($expected);

    $lines = explode("\n", $response->getContent());
    expect($lines)->not->toContain('Disallow: /app')->not->toContain('Disallow:');
});

test('the static files that would shadow the routes are gone', function () {
    expect(is_file(public_path('robots.txt')))->toBeFalse()
        ->and(is_file(public_path('sitemap.xml')))->toBeFalse();
});

test('the sitemap lists the home page only', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/xml; charset=UTF-8');

    $body = $response->getContent();
    expect($body)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');

    $xml = simplexml_load_string($body);
    expect($xml)->not->toBeFalse()
        ->and($xml->url)->toHaveCount(1)
        ->and((string) $xml->url[0]->loc)->toBe(url('/'));

    expect($this->get('/robots.txt')->getContent())->toContain('Sitemap: '.route('sitemap')."\n");
    expect(route('sitemap'))->toBe(url('sitemap.xml'));
});

test('the sitemap escapes the app url as xml', function () {
    url()->forceRootUrl('https://example.test/a&b');

    $xml = simplexml_load_string((new SitemapController)()->getContent());

    expect($xml)->not->toBeFalse()
        ->and((string) $xml->url[0]->loc)->toBe(url('/'));
});

test('the SEO files run no database query', function (string $path) {
    DB::enableQueryLog();

    $this->get($path)->assertOk();

    expect(DB::getQueryLog())->toBeEmpty();
})->with(['/robots.txt', '/sitemap.xml']);

test('the first link on the page skips to the main content', function () {
    $content = $this->get('/')->getContent();

    $body = strpos($content, '<body');
    $link = strpos($content, '<a ', $body);
    $header = strpos($content, '<header');

    expect($body)->not->toBeFalse()->and($link)->toBeGreaterThan($body)->and($link)->toBeLessThan($header);

    expect(preg_match('#<a [^>]*>[^<]*</a>#', substr($content, $link), $matches))->toBe(1);
    expect($matches[0])
        ->toContain('href="#main"')
        ->toContain('>Skip to content</a>')
        ->toContain('sr-only')
        ->toContain('focus:not-sr-only');

    expect($content)->toContain('<main id="main" tabindex="-1"');
    expect(substr_count($content, 'href="#main"'))->toBe(1);
});

test('the page never mentions features that do not exist', function (string $word, bool $loggedIn) {
    if ($loggedIn) {
        $this->actingAs(User::factory()->withPersonalOrganization()->create());
    }

    $content = $this->get('/')->assertOk()->getContent();

    expect(mb_strtolower($content))->not->toContain($word);
})->with(['pricing', 'free trial', 'goals', 'integrations', 'mobile app', 'two-factor'])
    ->with([
        'guest' => false,
        'logged in' => true,
    ]);

test('every icon is decorative', function () {
    $content = $this->get('/')->getContent();

    preg_match_all('/<svg\b[^>]*>/', $content, $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->toContain('aria-hidden="true"');
    }
});
