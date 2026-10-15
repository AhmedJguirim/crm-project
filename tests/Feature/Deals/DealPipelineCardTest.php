<?php

use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Eur]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->zeta = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zeta Corp']);
    $this->acme = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Ltd']);
    $this->contact->companies()->attach([$this->zeta->id, $this->acme->id]);

    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->acme->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'status' => DealStatus::Open,
        'value' => 1234.5,
        'expected_close_date' => '2026-10-06',
        'position' => '1000.0000000000',
    ]);

    $this->cardEntry = function (Deal $deal, string $name): ?TextEntry {
        $board = Livewire::test(DealPipeline::class)->instance()->getBoard();

        return collect($board->getCardSchema($deal)->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === $name);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the card shows title, subtitle, value and date without labels or buttons', function () {
    $page = Livewire::test(DealPipeline::class)
        ->assertSee('Retainer')
        ->assertSee('Jane Doe · Acme Ltd')
        ->assertSee('€1,234.50')
        ->assertSee('Oct 6, 2026')
        ->assertDontSee('Close Date')
        ->assertDontSee('No contact');

    expect($page->instance()->getBoard()->getRecordActions())->toBe([])
        ->and($page->instance()->getBoard()->getCardAction())->toBe('openDeal');
});

test('the close date is red only for an open deal closing before today', function (DealStatus $status, string $stage, string $date, string $color) {
    $this->deal->forceFill(['status' => $status, 'pipeline_stage_id' => pipelineStageNamed($this->org, $stage)->id, 'expected_close_date' => $date])->saveQuietly();

    $entry = ($this->cardEntry)($this->deal->fresh(), 'expected_close_date');

    expect($entry->getColor($entry->getState()))->toBe($color);
})->with([
    'open, past' => [DealStatus::Open, 'Lead', '2026-10-06', 'danger'],
    'won, past' => [DealStatus::Won, 'Won', '2026-10-06', 'gray'],
    'lost, past' => [DealStatus::Lost, 'Lost', '2026-10-06', 'gray'],
    'open, today' => [DealStatus::Open, 'Lead', '2026-10-09', 'gray'],
    'open, future' => [DealStatus::Open, 'Lead', '2026-10-12', 'gray'],
]);

test('the value uses the organization currency, zero is shown and null is hidden', function () {
    $this->org->update(['currency' => Currency::Gbp]);
    $this->deal->forceFill(['value' => 0])->saveQuietly();

    Livewire::test(DealPipeline::class)->assertSee('£0.00');

    $this->deal->forceFill(['value' => null])->saveQuietly();

    Livewire::test(DealPipeline::class)->assertDontSee('£0.00')->assertDontSee('—');

    expect(($this->cardEntry)($this->deal->fresh(), 'value')->isVisible())->toBeFalse();
});

test('the subtitle joins the live contact and the live company of the deal', function (?string $contactName, ?string $companyName, bool $trashContact, bool $trashCompany, ?string $expected) {
    $contact = $contactName === null ? null : Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $contactName]);
    $company = $companyName === null ? null : Company::factory()->create(['organization_id' => $this->org->id, 'name' => $companyName]);
    $deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact?->id,
        'company_id' => $company?->id,
        'created_by' => $this->user->id,
    ]);

    if ($trashContact) {
        $contact->delete();
    }

    if ($trashCompany) {
        $company->delete();
    }

    $entry = ($this->cardEntry)($deal->fresh(), 'subtitle');

    expect($entry->isVisible())->toBe($expected !== null);

    if ($expected !== null) {
        expect($entry->getState())->toBe($expected);
    }
})->with([
    'contact and company' => ['Jane Doe', 'Acme Ltd', false, false, 'Jane Doe · Acme Ltd'],
    'contact only' => ['John Roe', null, false, false, 'John Roe'],
    'company only' => [null, 'Acme Ltd', false, false, 'Acme Ltd'],
    'trashed contact' => ['Jane Doe', 'Acme Ltd', true, false, 'Acme Ltd'],
    'trashed company' => ['Jane Doe', 'Acme Ltd', false, true, 'Jane Doe'],
    'neither' => [null, null, false, false, null],
]);

test('the contact companies no longer feed the subtitle', function () {
    $this->deal->forceFill(['company_id' => null])->saveQuietly();

    Livewire::test(DealPipeline::class)->assertSee('Jane Doe')->assertDontSee('Acme Ltd')->assertDontSee('Zeta Corp');
});

test('a long subtitle is cut and carries a tooltip with the full text', function () {
    $name = str_repeat('A', 70);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $name]);
    $this->deal->forceFill(['contact_id' => $contact->id])->saveQuietly();

    $entry = ($this->cardEntry)($this->deal->fresh(), 'subtitle');

    expect($entry->formatState($entry->getState()))->toBe(str_repeat('A', 60).'...')
        ->and($entry->getTooltip())->toBe("{$name} · Acme Ltd");

    $short = ($this->cardEntry)(Deal::query()->with(['contact', 'company'])->find($this->deal->id)->setRelation('contact', $this->contact), 'subtitle');

    expect($short->getTooltip())->toBeNull();
});

test('every role opens the deal drawer by clicking the card', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);
    Filament::setTenant($this->org);

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id])
        ->assertSet('mountedActions.0.name', 'openDeal')
        ->assertNoRedirect()
        ->assertMountedActionModalSee('Retainer');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test("another organization's deal is neither shown nor opened", function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();
    $spy = Contact::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Spy Contact']);
    $otherDeal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $otherOrg->id,
        'contact_id' => $spy->id,
        'created_by' => $otherUser->id,
        'title' => 'Other Org Secret Deal',
    ]);

    $component = Livewire::test(DealPipeline::class)
        ->assertDontSee('Other Org Secret Deal')
        ->assertDontSee('Spy Contact')
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $otherDeal->id])
        ->assertNoRedirect();

    expect($component->instance()->mountedActions)->toBe([]);

    $component
        ->call('mountAction', 'openDeal', [], ['recordKey' => '999999'])
        ->assertNoRedirect();

    expect($component->instance()->mountedActions)->toBe([]);
});

test('html in a title, a contact name and a company name is escaped on the card', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => '<script>alert("c")</script>']);
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => '<b>Evil</b>']);
    $contact->companies()->attach($company);
    $this->deal->forceFill(['contact_id' => $contact->id, 'company_id' => $company->id, 'title' => '<img src=x onerror=alert(1)>'])->saveQuietly();

    Livewire::test(DealPipeline::class)
        ->assertSee('<img src=x onerror=alert(1)>')
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertDontSeeHtml('<script>alert("c")</script>')
        ->assertDontSeeHtml('<b>Evil</b>');
});

test('more cards do not add company queries', function () {
    $countCompanyQueries = function (): int {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, '"companies"')) {
                $count++;
            }
        });

        Livewire::test(DealPipeline::class);

        return $count;
    };

    $one = $countCompanyQueries();

    foreach (range(1, 6) as $i) {
        $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
        Deal::factory()->inStage('Lead')->create([
            'organization_id' => $this->org->id,
            'contact_id' => $contact->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->org->id])->id,
            'created_by' => $this->user->id,
            'status' => DealStatus::Open,
        ]);
    }

    expect($countCompanyQueries())->toBe($one)->toBeGreaterThan(0);
});

$pipelineCardElements = function (string $html): array {
    $dom = new DOMDocument;
    @$dom->loadHTML($html, LIBXML_NOERROR);

    return iterator_to_array($dom->getElementsByTagName('*'));
};

test('the outer card carries the click, the keys and the drag attributes', function () use ($pipelineCardElements) {
    $html = Livewire::test(DealPipeline::class)->html();
    $id = (string) $this->deal->id;
    $expression = "mountAction('openDeal', [], ".Js::from(['recordKey' => $id]).')';

    $card = collect($pipelineCardElements($html))
        ->first(fn (DOMElement $element): bool => $element->getAttribute('data-card-id') === $id);

    expect($card)->not->toBeNull()
        ->and($card->getAttribute('wire:click'))->toBe($expression)
        ->and($card->getAttribute('wire:keydown.enter'))->toBe($expression)
        ->and($card->getAttribute('wire:keydown.space.prevent'))->toBe($expression)
        ->and($card->getAttribute('tabindex'))->toBe('0')
        ->and($card->getAttribute('role'))->toBe('button')
        ->and($card->getAttribute('x-sortable-item'))->toBe($id)
        ->and($card->hasAttribute('x-sortable-handle'))->toBeTrue()
        ->and($card->getAttribute('class'))->toContain('cursor-pointer', 'focus-visible:ring-2')
        ->not->toContain('cursor-grab ');
});

test('one click mounts the action exactly once, on the card itself', function () use ($pipelineCardElements) {
    $second = Deal::factory()->inStage('Discovery')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Second Deal',
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
    ]);

    $elements = collect($pipelineCardElements(Livewire::test(DealPipeline::class)->html()));

    $clickable = $elements->filter(fn (DOMElement $element): bool => str_contains($element->getAttribute('wire:click'), "mountAction('openDeal'"));

    expect($clickable)->toHaveCount(2)
        ->and($clickable->map(fn (DOMElement $element): string => $element->getAttribute('data-card-id'))->sort()->values()->all())
        ->toBe(collect([(string) $this->deal->id, (string) $second->id])->sort()->values()->all());

    $inner = $elements->filter(fn (DOMElement $element): bool => $element->hasAttribute('wire:click')
        && ($element->tagName === 'h4' || str_contains(' '.$element->getAttribute('class').' ', ' px-3 pb-3 ')));

    expect($inner)->toHaveCount(0);
});
