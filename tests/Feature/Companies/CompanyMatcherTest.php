<?php

use App\Models\Company;
use App\Models\Organization;
use App\Services\Companies\CompanyMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->matcher = CompanyMatcher::forOrganization($this->org->id);

    $this->makeCompany = fn (string $name, ?string $website = null, ?Organization $organization = null, bool $trashed = false): Company => tap(
        Company::factory()->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'name' => $name,
            'website' => $website,
        ]),
        fn (Company $company) => $trashed ? $company->delete() : null,
    );
});

it('matches on the domain whatever the name', function () {
    $acme = ($this->makeCompany)('Acme', 'https://www.acme.com');

    $match = $this->matcher->match('whatever', 'acme.com');

    expect($match->isFound())->toBeTrue()
        ->and($match->company->is($acme))->toBeTrue()
        ->and($match->domain)->toBe('acme.com')
        ->and($match->name)->toBe('whatever');
});

it('matches on the domain without a name', function () {
    $acme = ($this->makeCompany)('Acme', 'acme.com');

    expect($this->matcher->match(null, 'https://ACME.com/about')->company->is($acme))->toBeTrue();
});

it('resolves a shared domain by name', function () {
    ($this->makeCompany)('Acme France', 'acme.com');
    $spain = ($this->makeCompany)('Acme Spain', 'acme.com');

    $found = $this->matcher->match('acme spain', 'https://acme.com');
    $italy = $this->matcher->match('Acme Italy', 'acme.com');
    $noName = $this->matcher->match(null, 'acme.com');

    expect($found->company->is($spain))->toBeTrue()
        ->and($italy->isFailed())->toBeTrue()
        ->and($italy->reason)->toBe('Several companies use the website acme.com. Merge them or import this row by hand.')
        ->and($noName->isFailed())->toBeTrue();
});

it('does not resolve a shared domain when two of them have the name', function () {
    ($this->makeCompany)('Acme', 'acme.com');
    ($this->makeCompany)('Acme', 'www.acme.com');

    expect($this->matcher->match('Acme', 'acme.com')->isFailed())->toBeTrue();
});

it('falls back to a company with that name and no website when the domain is unknown', function () {
    $acme = ($this->makeCompany)('Acme');

    $match = $this->matcher->match('ACME ', 'acme.com');

    expect($match->company->is($acme))->toBeTrue()
        ->and($match->domain)->toBe('acme.com');
});

it('fails when several companies without a website have the name and the domain is unknown', function () {
    ($this->makeCompany)('Acme');
    ($this->makeCompany)('Acme');

    $match = $this->matcher->match('Acme', 'acme.com');

    expect($match->reason)->toBe('Several companies are named Acme. Add the company website to choose.');
});

it('does not match a company that has another domain', function () {
    ($this->makeCompany)('Acme', 'acme.com');

    $match = $this->matcher->match('Acme', 'acme.co.uk');

    expect($match->isNotFound())->toBeTrue()
        ->and($match->name)->toBe('Acme')
        ->and($match->domain)->toBe('acme.co.uk');
});

it('matches by name only, ignoring case and surrounding spaces', function () {
    $acme = ($this->makeCompany)('Acme', 'acme.com');

    $match = $this->matcher->match('  acme ', null);

    expect($match->company->is($acme))->toBeTrue()
        ->and($match->name)->toBe('acme')
        ->and($match->domain)->toBeNull();
});

it('counts spaces inside a name', function () {
    ($this->makeCompany)('Acme Corp');

    expect($this->matcher->match('AcmeCorp', null)->isNotFound())->toBeTrue();
});

it('fails on an ambiguous name', function () {
    ($this->makeCompany)('Acme');
    ($this->makeCompany)('Acme', 'acme.com');

    $match = $this->matcher->match('Acme', null);

    expect($match->isFailed())->toBeTrue()
        ->and($match->reason)->toBe('Several companies are named Acme. Add the company website to choose.');
});

it('reports a trashed company instead of matching it', function (?string $name, ?string $website) {
    ($this->makeCompany)('Old Co', 'old.test', trashed: true);

    $match = $this->matcher->match($name, $website);

    expect($match->isFailed())->toBeTrue()
        ->and($match->reason)->toBe('The company Old Co is in the trash. Restore it first.');
})->with([
    'domain only' => [null, 'old.test'],
    'domain and another name' => ['Other', 'old.test'],
    'name only' => ['old co', null],
]);

it('ignores a trashed company without a website when the domain is unknown', function () {
    ($this->makeCompany)('Old Co', trashed: true);

    expect($this->matcher->match('Old Co', 'new.test')->isNotFound())->toBeTrue();
});

it('prefers an active company over a trashed one', function () {
    ($this->makeCompany)('Acme', trashed: true);
    $active = ($this->makeCompany)('Acme');

    expect($this->matcher->match('Acme', null)->company->is($active))->toBeTrue();
});

it('prefers an active company with the domain over a trashed one', function () {
    ($this->makeCompany)('Acme', 'acme.com', trashed: true);
    $active = ($this->makeCompany)('Acme Two', 'acme.com');

    expect($this->matcher->match(null, 'acme.com')->company->is($active))->toBeTrue();
});

it('fails on an invalid website', function (string $website) {
    $match = $this->matcher->match('Acme', $website);

    expect($match->isFailed())->toBeTrue()
        ->and($match->reason)->toBe("Invalid value for field 'company website': {$website}");
})->with(['not a website', 'ftp://acme.com', 'localhost']);

it('does not find anything when nothing matches', function () {
    $match = $this->matcher->match('Nova', 'nova.io');

    expect($match->isNotFound())->toBeTrue()
        ->and($match->isFound())->toBeFalse()
        ->and($match->isFailed())->toBeFalse()
        ->and($match->company)->toBeNull()
        ->and($match->name)->toBe('Nova')
        ->and($match->domain)->toBe('nova.io');
});

it('throws when both the name and the website are blank', function (?string $name, ?string $website) {
    $this->matcher->match($name, $website);
})->with([
    [' ', null],
    [null, ''],
    ['', '  '],
])->throws(InvalidArgumentException::class);

it('never sees the companies of another organization', function () {
    $globex = Organization::factory()->create();
    ($this->makeCompany)('Acme', 'acme.com', $globex);
    ($this->makeCompany)('Acme', null, $globex, trashed: true);

    expect($this->matcher->match('Acme', 'acme.com')->isNotFound())->toBeTrue()
        ->and($this->matcher->match('Acme', null)->isNotFound())->toBeTrue()
        ->and(CompanyMatcher::forOrganization($globex->id)->match('Acme', 'acme.com')->isFound())->toBeTrue();
});

it('works without a tenant context', function () {
    $acme = ($this->makeCompany)('Acme', 'acme.com');

    expect(app(TenantContext::class)->id())->toBeNull()
        ->and($this->matcher->match('Acme', 'acme.com')->company->is($acme))->toBeTrue()
        ->and($this->matcher->match('Acme', null)->company->is($acme))->toBeTrue();
});

it('finds a remembered company with a single query', function () {
    $nova = ($this->makeCompany)('Nova', 'nova.io');
    $this->matcher->remember($nova);

    DB::enableQueryLog();
    $match = $this->matcher->match('Nova', 'nova.io');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($match->company->is($nova))->toBeTrue()
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('"id" = ?');
});

it('finds a remembered company without a website by name', function () {
    $nova = ($this->makeCompany)('Nova');
    $this->matcher->remember($nova);
    ($this->makeCompany)('Nova', 'nova.io');

    DB::enableQueryLog();
    $byName = $this->matcher->match('NOVA', null);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($byName->company->is($nova))->toBeTrue()
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('"id" = ?')
        ->and($this->matcher->match('nova', 'other.io')->company->is($nova))->toBeTrue();
});

it('remembers a match so the next rows need one query', function () {
    $acme = ($this->makeCompany)('Acme', 'acme.com');
    $this->matcher->match('Acme', 'acme.com');

    DB::enableQueryLog();
    $this->matcher->match('Other', 'https://www.acme.com');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('"id" = ?')
        ->and($this->matcher->match(null, 'acme.com')->company->is($acme))->toBeTrue();
});

it('remembers a match by name so the next rows need one query', function () {
    $acme = ($this->makeCompany)('Acme', 'acme.com');
    $this->matcher->match('Acme', null);

    DB::enableQueryLog();
    $match = $this->matcher->match(' ACME', null);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($match->company->is($acme))->toBeTrue()
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('"id" = ?');
});

it('does not use a name remembered for a company that has a domain to match a row with another domain', function () {
    ($this->makeCompany)('Acme', 'acme.com');
    $this->matcher->match('Acme', null);

    expect($this->matcher->match('Acme', 'acme.co.uk')->isNotFound())->toBeTrue();
});

it('stops using a remembered name once a second company with that name was created', function () {
    ($this->makeCompany)('Acme', 'acme.com');
    $reason = 'Several companies are named Acme. Add the company website to choose.';

    expect($this->matcher->match('Acme', null)->isFound())->toBeTrue();

    $second = ($this->makeCompany)('Acme', 'acme.co.uk');
    $this->matcher->remember($second);

    $match = $this->matcher->match('Acme', null);

    expect($match->isFailed())->toBeTrue()
        ->and($match->reason)->toBe($reason)
        ->and(CompanyMatcher::forOrganization($this->org->id)->match('Acme', null)->reason)->toBe($reason);
});

it('stops reloading a remembered company once it is gone', function () {
    $nova = ($this->makeCompany)('Nova', 'nova.io');
    $this->matcher->remember($nova);
    $nova->delete();
    $this->matcher->match('Nova', 'nova.io');

    DB::enableQueryLog();
    $this->matcher->match('Nova', 'nova.io');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], '"id" = ?')))->toBeEmpty();
});

it('compares names ignoring the spaces stored around them', function () {
    $acme = ($this->makeCompany)('  Acme  ');

    expect($this->matcher->match('acme', null)->company->is($acme))->toBeTrue();
});

it('does not memorize a company that was only picked by name among a shared domain', function () {
    ($this->makeCompany)('Acme France', 'acme.com');
    ($this->makeCompany)('Acme Spain', 'acme.com');
    $this->matcher->match('Acme Spain', 'acme.com');

    expect($this->matcher->match('Acme Italy', 'acme.com')->isFailed())->toBeTrue();
});

it('drops a remembered company that was deleted meanwhile', function () {
    $nova = ($this->makeCompany)('Nova', 'nova.io');
    $this->matcher->remember($nova);
    $nova->delete();

    $match = $this->matcher->match('Nova', 'nova.io');

    expect($match->isFailed())->toBeTrue()
        ->and($match->reason)->toBe('The company Nova is in the trash. Restore it first.');
});

it('drops a remembered company that no longer exists', function () {
    $nova = ($this->makeCompany)('Nova');
    $this->matcher->remember($nova);
    DB::table('companies')->where('id', $nova->id)->delete();

    expect($this->matcher->match('Nova', null)->isNotFound())->toBeTrue();
});

it('never writes', function () {
    ($this->makeCompany)('Acme', 'acme.com', trashed: true);
    ($this->makeCompany)('Acme Two');

    $this->matcher->match('Acme', 'acme.com');
    $this->matcher->match('Acme Two', null);
    $this->matcher->match('Nova', 'nova.io');

    expect(Company::query()->forOrganization($this->org->id)->withTrashed()->count())->toBe(2)
        ->and(Company::query()->forOrganization($this->org->id)->onlyTrashed()->count())->toBe(1);
});

it('queries again once the memo is forgotten, and no longer matches a stale website', function () {
    $acme = ($this->makeCompany)('Acme', 'acme.com');
    $this->matcher->match('Acme', 'acme.com');
    $acme->update(['website' => 'acme.io']);

    expect($this->matcher->match('Acme', 'acme.com')->isFound())->toBeTrue();

    $this->matcher->forgetRemembered();

    DB::enableQueryLog();
    $stale = $this->matcher->match('Acme', 'acme.com');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($stale->isNotFound())->toBeTrue()
        ->and($queries[0]['query'])->toContain('"domain" = ?')
        ->and($this->matcher->match('Acme', 'acme.io')->company->is($acme))->toBeTrue();
});
