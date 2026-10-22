<?php

use App\Enums\Currency;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

test('a guest sees the landing page instead of a redirect', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Your clients, deals and invoices,', false);
    $response->assertSee('in one place.', false);
    $response->assertSee('CRM for consultants, freelancers and small sales teams', false);
    $response->assertSee('<html lang="en">', false);
    $response->assertSee('<meta name="theme-color" content="#2563eb">', false);
    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

test('a guest gets the panel login and registration links, never the Fortify ones', function () {
    $panel = Filament::getPanel('admin');

    $response = $this->get('/');

    $response->assertSee('href="'.$panel->getLoginUrl().'"', false);
    $response->assertSee('href="'.$panel->getRegistrationUrl().'"', false);
    $response->assertSee('Log in');
    $response->assertSee('Get started');
    $response->assertDontSee('href="'.route('login').'"', false);
    $response->assertDontSee('Open the app');
});

test('a logged-in user sees Open the app and no sign-up button', function () {
    $user = User::factory()->withPersonalOrganization()->create();
    $this->actingAs($user);
    $appUrl = Filament::getPanel('admin')->getUrl();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Open the app');
    $response->assertSee('href="'.$appUrl.'"', false);
    expect($appUrl)->toStartWith(url('/admin'));
    $response->assertDontSee('Get started');
    $response->assertDontSee('Log in');
});

test('a guest request runs no database query', function () {
    DB::enableQueryLog();

    $this->get('/')->assertOk();

    expect(DB::getQueryLog())->toBeEmpty();
});

test('the page ships no Livewire, Filament or Flux asset and no Alpine attribute', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect($content)
        ->not->toContain('livewire')
        ->not->toContain('filament')
        ->not->toContain('flux')
        ->not->toContain('x-data');
});

test('the theme toggle and the no-flash script are present', function () {
    $response = $this->get('/');

    $response->assertSee('data-theme-toggle', false);
    $response->assertSee('aria-label="Toggle dark mode"', false);
    $response->assertSee("localStorage.getItem('theme')", false);
});

test('the home route keeps its name', function () {
    expect(route('home'))->toBe(url('/'));
});

test('a guest sees the navbar links, the drawer and the guest CTAs', function () {
    $panel = Filament::getPanel('admin');
    $loginUrl = $panel->getLoginUrl();
    $registerUrl = $panel->getRegistrationUrl();

    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    foreach ([
        'data-landing-navbar',
        'data-scrolled="false"',
        '<nav aria-label="Main"',
        'href="#features"',
        'href="#pipeline"',
        'href="#reports"',
        'href="#teams"',
        'id="mobile-menu"',
        'role="dialog"',
        'aria-modal="true"',
        'aria-controls="mobile-menu"',
        'aria-expanded="false"',
        'aria-label="Open menu"',
        'aria-label="Close menu"',
        'data-menu-open',
        'data-menu-backdrop',
    ] as $needle) {
        $response->assertSee($needle, false);
    }

    foreach (['Features', 'Pipeline', 'Reports', 'Teams'] as $label) {
        $response->assertSee($label);
    }

    expect(substr_count($content, 'href="'.$loginUrl.'"'))->toBe(3)
        ->and(substr_count($content, 'href="'.$registerUrl.'"'))->toBe(4)
        ->and(substr_count($content, 'data-theme-toggle'))->toBe(1)
        ->and(substr_count($content, 'href="#features"'))->toBe(5)
        ->and(substr_count($content, 'href="#pipeline"'))->toBe(3)
        ->and(substr_count($content, 'href="#reports"'))->toBe(3)
        ->and(substr_count($content, 'href="#teams"'))->toBe(3)
        ->and(substr_count($content, 'href="#import"'))->toBe(1);
});

test('the hero has the headline, the lead and both CTAs', function () {
    $response = $this->get('/')->assertOk();

    $response->assertSee('<section id="top"', false);
    $response->assertSee('Your clients, deals and invoices,', false);
    $response->assertSee('in one place.', false);
    $response->assertSee('without a spreadsheet in sight.', false);
    $response->assertSee('See the features');
    $response->assertSee('aria-label="Scroll to the features"', false);
    $response->assertSee('data-hero-visual', false);
    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

test('the footer shows the brand, the links and the copyright only', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    $response = $this->get('/')->assertOk();

    expect(substr_count($response->getContent(), '<footer'))->toBe(1);
    $response->assertSee('A simple CRM for consultants, freelancers and small sales teams.');
    $response->assertSee('Product');
    $response->assertSee('Account');
    $response->assertSee('Import and export');
    $response->assertSee('href="#import"', false);
    $response->assertSee('Sign up');
    $response->assertSee('© 2027 CRM');
});

test('a logged-in user gets Open the app everywhere and no guest CTA', function () {
    $user = User::factory()->withPersonalOrganization()->create();
    $this->actingAs($user);
    $panel = Filament::getPanel('admin');
    $appUrl = $panel->getUrl();

    $response = $this->get('/')->assertOk();

    expect(substr_count($response->getContent(), 'href="'.$appUrl.'"'))->toBe(4);
    $response->assertDontSee('Sign up');
    $response->assertDontSee('Get started');
    $response->assertDontSee('Log in');
    $response->assertDontSee('href="'.$panel->getRegistrationUrl().'"', false);
    $response->assertDontSee('href="'.$panel->getLoginUrl().'"', false);
    $response->assertSee('See the features');
});

test('the page ships no Alpine and no forbidden script attributes', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect($content)
        ->not->toContain('x-data')
        ->not->toContain('x-show')
        ->not->toContain('@click');
});

test('a guest sees the facts slab with four tiles and their real values', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    $response->assertSee('<section id="facts"', false);
    $response->assertSee('id="facts-heading"', false);
    $response->assertSee('Built in', false);
    $response->assertSee('Ready the day you sign up', false);
    $response->assertSee('Every new workspace comes with these. Nothing to install, nothing to configure first.', false);
    expect(substr_count($content, 'data-counter'))->toBe(4)
        ->and(substr_count($content, 'data-target="6"'))->toBe(2)
        ->and(substr_count($content, 'data-target="4"'))->toBe(1)
        ->and(substr_count($content, 'data-target="5"'))->toBe(1);
    $response->assertSeeInOrder(['Report tabs', 'Roles', 'Currencies', 'Pipeline stages ready on day one'], false);
    $response->assertSee('Sales results, Conversion, Velocity, Money, Sources, Team activity', false);
    $response->assertSee('Owner, Admin, Member, Viewer', false);
    $response->assertSee('USD, EUR, GBP, CAD, AUD', false);
    $response->assertSee('Lead, Discovery, Proposal Sent, Negotiating, Won, Lost', false);
});

test('a guest sees the features section with six cards and the exact copy', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    $response->assertSee('<section id="features"', false);
    $response->assertSee('id="features-heading"', false);
    $response->assertSee('Six tools that work together, from the first contact to the last payment.', false);
    expect(substr_count($content, 'id="features"'))->toBe(1)
        ->and(substr_count($content, '<article'))->toBe(6)
        ->and(substr_count($content, '<h3'))->toBe(6)
        ->and(substr_count($content, '<h2'))->toBe(2)
        ->and(substr_count($content, '<h1'))->toBe(1);
    $response->assertSeeInOrder([
        'Contacts and companies',
        'Activity history',
        'Smart segments',
        'Tasks and reminders',
        'Invoices and payments',
        'Search everything',
    ], false);
    $response->assertSee('Store people and the companies they work for, with tags, an owner, a status and a lead source. Admins can add custom fields — text, numbers, dates, dropdowns — to match how you work.', false);
    $response->assertSee('Log calls, emails, meetings, WhatsApp messages and notes on a contact or a deal, and read them back in one timeline.', false);
    $response->assertSee('Build lists with rules — a tag, a field, a company, recent activity or an open deal. Once an admin publishes a list, it stays up to date by itself, and admins can export its members.', false);
    $response->assertSee("Assign follow-ups, snooze them or mark them done in one click, and get a reminder before they're due plus a daily digest by email.", false);
    $response->assertSee('Create an invoice from a won deal, download it as a PDF, and record each payment as it comes in — the balance and status follow. Admins can record refunds.', false);
    $response->assertSee('Find any contact, company, deal, task or invoice from the search bar.', false);
    $response->assertSee('Admins can add custom fields', false);
    $response->assertSee('Admins can record refunds', false);
    $response->assertSee('Once an admin publishes a list, it stays up to date by itself', false);
    $response->assertSee('admins can export its members.', false);
    expect($content)
        ->not->toContain('payment or refund')
        ->not->toContain('stay up to date by themselves')
        ->not->toContain('export any segment');
});

test('the facts on the page match the code', function () {
    $organization = Organization::factory()->create();

    expect(count(OrganizationRole::cases()))->toBe(4)
        ->and(array_map(fn (Currency $currency) => $currency->value, Currency::cases()))->toBe(['USD', 'EUR', 'GBP', 'CAD', 'AUD'])
        ->and(DefaultPipeline::stagesOf($organization->id)->pluck('name')->all())->toBe(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost'])
        ->and(substr_count(file_get_contents(app_path('Filament/Pages/Reports.php')), "Tab::make('"))->toBe(6);
});

test('a logged-in user sees the same facts and features', function () {
    $this->actingAs(User::factory()->withPersonalOrganization()->create());

    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    expect(substr_count($content, 'data-counter'))->toBe(4)
        ->and(substr_count($content, '<article'))->toBe(6);
    $response->assertSee('Search everything');
});

test('the new sections add no link, no Alpine and no clamped text', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect(substr_count($content, 'href="#features"'))->toBe(5)
        ->and($content)
        ->not->toContain('x-data')
        ->not->toContain('@click')
        ->not->toContain('line-clamp');
});

test('each counter renders its final value on the server', function () {
    $content = $this->get('/')->assertOk()->getContent();

    preg_match_all('/data-counter data-target="(\d+)"[^>]*>\s*(\d+)\s*</', $content, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(4)
        ->and(array_map(fn (array $match) => [$match[1], $match[2]], $matches))->toBe([['6', '6'], ['4', '4'], ['5', '5'], ['6', '6']]);
});
