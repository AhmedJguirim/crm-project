<?php

use App\Enums\Currency;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\Deals\DefaultPipeline;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

function landingSectionHtml(string $content, string $id): string
{
    $start = strpos($content, '<section id="'.$id.'"');
    expect($start)->not->toBeFalse();

    return substr($content, $start, strpos($content, '</section>', $start) - $start);
}

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

    expect(substr_count($content, 'href="'.$loginUrl.'"'))->toBe(4)
        ->and(substr_count($content, 'href="'.$registerUrl.'"'))->toBe(5)
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

    expect(substr_count($response->getContent(), 'href="'.$appUrl.'"'))->toBe(5);
    $response->assertDontSee('>Sign up<', false);
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
        ->and(substr_count(landingSectionHtml($content, 'features'), '<article'))->toBe(6)
        ->and(substr_count(landingSectionHtml($content, 'features'), '<h3'))->toBe(6)
        ->and(substr_count($content, '<h2'))->toBe(7)
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
        ->and(DefaultPipeline::stagesOf($organization->id)->firstWhere('name', 'Proposal Sent')->probability)->toBe(50)
        ->and(substr_count(file_get_contents(app_path('Filament/Pages/Reports.php')), "Tab::make('"))->toBe(6)
        ->and(ContactImportFileReader::MAX_UPLOAD_KILOBYTES)->toBe(10240);
});

test('a logged-in user sees the same facts and features', function () {
    $this->actingAs(User::factory()->withPersonalOrganization()->create());

    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    expect(substr_count($content, 'data-counter'))->toBe(4)
        ->and(substr_count(landingSectionHtml($content, 'features'), '<article'))->toBe(6);
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

test('a guest sees the pipeline section with its three rows and the win-probability card', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();
    $section = landingSectionHtml($content, 'pipeline');

    $response->assertSee('<section id="pipeline"', false);
    $response->assertSee('id="pipeline-heading"', false);
    $response->assertSee('A pipeline you can', false);
    $response->assertSee('See where every deal stands and what it needs next.', false);
    expect(substr_count($content, 'id="pipeline"'))->toBe(1)
        ->and(substr_count($section, '<h3'))->toBe(3)
        ->and(substr_count($section, '<article'))->toBe(0)
        ->and(substr_count($section, 'data-pipeline-visual'))->toBe(1)
        ->and($section)
        ->toContain('Proposal Sent')
        ->toContain('50% win probability')
        ->toContain('w-1/2');
    $response->assertSeeInOrder(['Drag deals across stages', 'Stages that fit your process', 'Know why deals stall or slip away'], false);
    $response->assertSee('A board with a column per stage, the total and weighted value under each header, and a side drawer to move, edit or log activity without leaving the board.', false);
    $response->assertSee('Admins can rename, recolor, reorder or add stages, each with a win probability. Every new workspace starts with Lead, Discovery, Proposal Sent, Negotiating, Won and Lost.', false);
    $response->assertSee('Time in stage on each card, a stage history on each deal, a "Needs attention" list, and an optional lost reason when a deal is lost.', false);
    $response->assertSee('Admins can rename, recolor, reorder or add stages', false);
    expect($content)
        ->not->toContain('Rename, recolor')
        ->not->toContain('on every card');
});

test('a guest sees the reports section with six cards and the exact copy', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();
    $section = landingSectionHtml($content, 'reports');

    $response->assertSee('<section id="reports"', false);
    $response->assertSee('id="reports-heading"', false);
    $response->assertSee('Numbers you can', false);
    $response->assertSee('Open the dashboard to see your day; open Reports to see the trend. Filter both by period and, in a team, by owner.', false);
    expect(substr_count($content, 'id="reports"'))->toBe(1)
        ->and(substr_count($section, '<article'))->toBe(6)
        ->and(substr_count($section, '<h3'))->toBe(6)
        ->and(substr_count($section, 'data-reports-visual'))->toBe(1);
    $response->assertSeeInOrder(['My day', 'Key figures', "What you're owed", 'Revenue and forecast', 'Reports', 'Export for your accountant'], false);
    $response->assertSee('Your overdue tasks, tasks due today and your deals closing this week.', false);
    $response->assertSee('Won revenue, deals won, win rate, new deals, cash collected and new contacts, each compared with the previous period, with a small trend line.', false);
    $response->assertSee('Unpaid balances by how late they are, and the oldest overdue invoices.', false);
    $response->assertSee('Cash collected and won value by month for the last 12 months, and open deals by expected close month, weighted by probability.', false);
    $response->assertSee('Win rate and lost reasons, stage conversion, time in stage and sales velocity, revenue by client, leads and won deals by source, and a team activity leaderboard.', false);
    $response->assertSee('Admins can export the team leaderboard and revenue by client to Excel or CSV.', false);
    expect($content)->not->toContain('by period and by owner');
});

test('the page outline stays in order', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    expect(substr_count($content, '<h1'))->toBe(1)
        ->and(substr_count($content, '<h2'))->toBe(7);
    $response->assertSeeInOrder(['id="facts"', 'id="features"', 'id="pipeline"', 'id="reports"', 'id="teams"', 'id="import"', 'id="get-started"', '<footer'], false);
});

test('the pipeline and reports sections add no link, no image and no Alpine', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect(substr_count($content, 'href="#pipeline"'))->toBe(3)
        ->and(substr_count($content, 'href="#reports"'))->toBe(3)
        ->and(landingSectionHtml($content, 'pipeline'))->not->toContain('href=')->not->toContain('<img')
        ->and(landingSectionHtml($content, 'reports'))->not->toContain('href=')->not->toContain('<img')
        ->and($content)->not->toContain('x-data')->not->toContain('@click');
});

test('a logged-in user sees the pipeline and reports sections', function () {
    $this->actingAs(User::factory()->withPersonalOrganization()->create());

    $content = $this->get('/')->assertOk()->getContent();

    expect($content)->toContain('id="pipeline"')->toContain('id="reports"')
        ->and(substr_count(landingSectionHtml($content, 'reports'), '<article'))->toBe(6);
});

test('a guest sees the teams section with the four roles and the exact copy', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();
    $section = landingSectionHtml($content, 'teams');

    expect($content)->toContain('<section id="teams"')->toContain('id="teams-heading"')
        ->and(substr_count($content, 'id="teams"'))->toBe(1)
        ->and(substr_count($section, '<li'))->toBe(4)
        ->and(substr_count($section, '<h2'))->toBe(1)
        ->and(substr_count($section, '<h3'))->toBe(0)
        ->and(substr_count($section, '<article'))->toBe(0)
        ->and($section)->not->toContain('href=')->not->toContain('<img')
        ->and($content)->not->toContain('manage settings')->not->toContain('admins also import');
    $response->assertSee('Work alone or', false);
    $response->assertSee('as a team', false);
    $response->assertSee('Sign up and you get your own workspace. Create an organization for your team, invite people by email and give each one a role.', false);
    $response->assertSeeInOrder([
        '>Viewers</span> see everything and change nothing.',
        '>Members</span> also add and edit contacts, companies, deals, tasks and invoices.',
        '>Admins</span> also delete, import and export, invite people and change their roles.',
        ">The owner</span> also sets the organization's name, time zone, currency and logo.",
    ], false);
    $response->assertSee("Each organization keeps its own data, separate from every other organization's. Belong to several organizations and switch between them in one click.", false);
});

test('a guest sees the import and export section with the exact copy', function () {
    $response = $this->get('/')->assertOk();
    $content = $response->getContent();
    $section = landingSectionHtml($content, 'import');

    expect($content)->toContain('<section id="import"')->toContain('id="import-heading"')
        ->and(substr_count($content, 'id="import"'))->toBe(1)
        ->and(substr_count($section, '<li'))->toBe(4)
        ->and(substr_count($section, '<h2'))->toBe(1)
        ->and($section)->not->toContain('href=')->not->toContain('<img');
    $response->assertSee('Move in', false);
    $response->assertSee('in minutes', false);
    $response->assertSee('Admins can import contacts and companies from Excel or CSV, and export them again at any time.', false);
    $response->assertSeeInOrder([
        'A ready-made Excel template to download',
        'CSV files separated by semicolons work too',
        'Choose to create, update or both',
        'Rows that fail come back in a file you can fix and upload again',
    ], false);
    $response->assertSee('Excel (.xlsx) or CSV, up to 10 MB per file.', false);
});

test('a guest sees the final call to action with Get started and Log in', function () {
    $panel = Filament::getPanel('admin');

    $content = $this->get('/')->assertOk()->getContent();
    $cta = landingSectionHtml($content, 'get-started');

    expect($content)->toContain('<section id="get-started"')->toContain('id="get-started-heading"')
        ->and($cta)->toContain('Ready when you are.')->toContain('Create your account and your pipeline is waiting.')
        ->and(substr_count($cta, 'href="'.$panel->getRegistrationUrl().'"'))->toBe(1)
        ->and(substr_count($cta, 'href="'.$panel->getLoginUrl().'"'))->toBe(1)
        ->and($cta)->toContain('>Get started<')->toContain('>Log in<')->not->toContain('Open the app');
});

test('a logged-in user sees Open the app in the final call to action', function () {
    $this->actingAs(User::factory()->withPersonalOrganization()->create());
    $panel = Filament::getPanel('admin');

    $content = $this->get('/')->assertOk()->getContent();
    $cta = landingSectionHtml($content, 'get-started');

    expect(substr_count($cta, 'href="'.$panel->getUrl().'"'))->toBe(1)
        ->and($cta)->toContain('Your pipeline is waiting.')
        ->not->toContain('Create your account')
        ->not->toContain('Get started')
        ->not->toContain('Log in')
        ->not->toContain($panel->getLoginUrl())
        ->not->toContain($panel->getRegistrationUrl())
        ->and($content)->toContain('id="teams"')->toContain('id="import"');
});
