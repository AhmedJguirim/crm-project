<?php

use App\Enums\ActivityType;
use App\Enums\Currency;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Schemas\DealDrawerInfolist;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
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

    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'value' => 1234.5,
        'expected_close_date' => '2026-10-06',
        'position' => '1000.0000000000',
    ]);

    $this->openDrawer = fn (Deal $deal): Testable => Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $deal->id]);

    $this->drawerEntry = function (Testable $component, string $name): ?TextEntry {
        $instance = $component->instance();
        $action = $instance->getMountedAction();
        $schema = $action->getSchema(Schema::make($instance)->model($action->getRecord()));

        return collect($schema->getFlatComponents(withHidden: true))
            ->first(fn ($entry): bool => $entry instanceof TextEntry && $entry->getName() === $name);
    };

    $this->task = fn (string $title, ?string $due, TaskStatus $status = TaskStatus::Pending, ?Deal $deal = null): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'deal_id' => ($deal ?? $this->deal)->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'due_at' => $due,
        'status' => $status,
    ]);

    $this->activity = fn (ActivityType $type, string $date, ?string $subject, ?Deal $deal = null): Activity => Activity::factory()->type($type)->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->user->id,
        'deal_id' => ($deal ?? $this->deal)->id,
        'occurred_at' => $date,
        'subject' => $subject,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('every role opens the drawer from the card', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);
    Filament::setTenant($this->org);

    ($this->openDrawer)($this->deal)
        ->assertSet('mountedActions.0.name', 'openDeal')
        ->assertNoRedirect()
        ->assertMountedActionModalSee(['Retainer', 'Jane Doe · Acme Ltd', 'Lead', 'Open', '€1,234.50', 'Oct 6, 2026', 'Open full page', 'Close']);
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('the drawer is a slide-over without a submit button, closed by clicking outside', function () {
    $action = ($this->openDrawer)($this->deal)->instance()->getMountedAction();

    expect($action->isModalSlideOver())->toBeTrue()
        ->and($action->isModalClosedByClickingAway())->toBeTrue()
        ->and($action->getModalSubmitAction())->toBeNull()
        ->and($action->getModalCancelActionLabel())->toBe('Close');
});

test('the overdue close date is red only for an open deal', function () {
    $entry = ($this->drawerEntry)(($this->openDrawer)($this->deal), 'expected_close_date');

    expect($entry->getColor($entry->getState()))->toBe('danger');

    $this->deal->forceFill(['status' => DealStatus::Won, 'stage' => DealStage::Won])->saveQuietly();

    $entry = ($this->drawerEntry)(($this->openDrawer)($this->deal), 'expected_close_date');

    expect($entry->getColor($entry->getState()))->toBe('gray');
});

test('next tasks are pending only, nearest first, without a due date last, at most three', function () {
    ($this->task)('Send proposal', '2026-10-12 09:00:00');
    ($this->task)('Call back', '2026-10-10 09:00:00');
    $noDate = ($this->task)('Sign NDA', null);
    ($this->task)('Old task', '2026-10-01 09:00:00', TaskStatus::Done);
    ($this->task)('Trashed', '2026-10-09 09:00:00')->delete();
    ($this->task)('Fourth', '2026-10-20 09:00:00');
    ($this->task)('Other deal task', '2026-10-09 10:00:00', deal: Deal::factory()->create(['organization_id' => $this->org->id]));

    expect(DealDrawerInfolist::nextTaskLines($this->deal))->toBe([
        'Call back — due Oct 10, 2026',
        'Send proposal — due Oct 12, 2026',
        'Fourth — due Oct 20, 2026',
    ]);

    Task::query()->where('id', '!=', $noDate->id)->delete();

    expect(DealDrawerInfolist::nextTaskLines($this->deal))->toBe(['Sign NDA — no due date']);
});

test('recent activities are newest first, at most three', function () {
    ($this->activity)(ActivityType::Call, '2026-10-01 10:00:00', 'Kick-off');
    ($this->activity)(ActivityType::Email, '2026-10-05 10:00:00', 'Quote sent');
    ($this->activity)(ActivityType::Meeting, '2026-10-08 10:00:00', '');
    ($this->activity)(ActivityType::Note, '2026-09-01 10:00:00', 'Old');

    expect(DealDrawerInfolist::recentActivityLines($this->deal))->toBe([
        'Meeting · Oct 8, 2026',
        'Email · Oct 5, 2026 · Quote sent',
        'Call · Oct 1, 2026 · Kick-off',
    ]);
});

test('empty lists and missing facts show placeholders', function () {
    $empty = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'created_by' => $this->user->id,
        'title' => 'Empty',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'value' => null,
        'expected_close_date' => null,
        'position' => '2000.0000000000',
    ]);

    ($this->openDrawer)($empty)->assertMountedActionModalSee(['Empty', 'No open tasks', 'No activities yet', '—']);
});

test("another organization's deal and an unknown id open nothing", function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherDeal = Deal::factory()->create([
        'organization_id' => $otherUser->personalOrganization()->id,
        'created_by' => $otherUser->id,
        'title' => 'Other Org Secret Deal',
        'stage' => DealStage::Lead,
    ]);

    foreach ([(string) $otherDeal->id, '999999'] as $key) {
        $component = Livewire::test(DealPipeline::class)
            ->call('mountAction', 'openDeal', [], ['recordKey' => $key])
            ->assertNoRedirect()
            ->assertDontSee('Other Org Secret Deal');

        expect($component->instance()->mountedActions)->toBe([])
            ->and($component->html())->not->toContain('Other Org Secret Deal');
    }
});

test('the open full page action links to the deal page', function () {
    $action = ($this->openDrawer)($this->deal)->instance()->getMountedAction()->getModalAction('openFullPage');

    expect($action->getUrl())->toBe(DealResource::getUrl('view', ['record' => $this->deal]))
        ->and($action->shouldOpenUrlInNewTab())->toBeFalse();
});

test('html in a task title and an activity subject is escaped in the drawer', function () {
    ($this->task)('<b>x</b>', '2026-10-12 09:00:00');
    ($this->activity)(ActivityType::Note, '2026-10-08 10:00:00', '<script>alert(1)</script>');

    $html = ($this->openDrawer)($this->deal)->getMountedActionModalHtml();

    expect($html)->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

test('opening and closing the drawer keeps the search and the filters', function () {
    $component = Livewire::test(DealPipeline::class)
        ->set('tableSearch', 'Ret')
        ->set('tableFilters.status.value', DealStatus::Open->value);

    $before = $component->get('tableFilters');

    $component
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id])
        ->call('unmountAction')
        ->assertNoRedirect()
        ->assertSet('tableSearch', 'Ret')
        ->assertSet('tableFilters', $before);

    expect($component->instance()->mountedActions)->toBe([]);
});

test('a deal deleted while its drawer is open does not crash', function () {
    $component = ($this->openDrawer)($this->deal);

    $this->deal->delete();

    $component->call('$refresh')->assertOk();
});
