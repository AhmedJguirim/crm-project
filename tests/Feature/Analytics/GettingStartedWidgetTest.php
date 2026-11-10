<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\GettingStartedWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Task;
use App\Models\User;
use App\Support\Onboarding\GettingStartedChecklist;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Livewire\invade;

const GETTING_STARTED_DESCRIPTION = "of 4 done. Each step ticks itself once it's done.";

beforeEach(function () {
    $this->acme = Organization::factory()->create(['timezone' => 'UTC']);
    $this->globex = Organization::factory()->create();

    $this->userOf = function (OrganizationRole $role, ?Organization $organization = null): User {
        $user = User::factory()->onboardingCompleted()->create();
        ($organization ?? $this->acme)->members()->attach($user, ['role' => $role->value]);

        return $user;
    };
    $this->owner = ($this->userOf)(OrganizationRole::Owner);
    $this->owner->organizations()->attach($this->globex, ['role' => OrganizationRole::Owner->value]);
    $this->admin = ($this->userOf)(OrganizationRole::Admin);
    $this->member = ($this->userOf)(OrganizationRole::Member);
    $this->viewer = ($this->userOf)(OrganizationRole::Viewer);

    $this->recordsOf = [
        'contact' => fn (Organization $org) => Contact::factory()->create(['organization_id' => $org->id]),
        'deal' => fn (Organization $org) => Deal::factory()->create(['organization_id' => $org->id]),
        'task' => fn (Organization $org) => Task::factory()->create(['organization_id' => $org->id]),
        'invoice' => function (Organization $org): Invoice {
            $contact = Contact::factory()->create(['organization_id' => $org->id]);
            $invoice = Invoice::factory()->create(['organization_id' => $org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Draft]);
            $contact->delete();

            return $invoice;
        },
    ];

    $this->openAs = function (User $user, ?Organization $organization = null) {
        $organization ??= $this->acme;
        $this->actingAs($user);
        Filament::setTenant($organization);

        return $this->get(Dashboard::getUrl(tenant: $organization));
    };
    $this->steps = function (User $user, ?Organization $organization = null): array {
        $this->actingAs($user);
        Filament::setTenant($organization ?? $this->acme);

        return invade(Livewire::test(GettingStartedWidget::class)->instance())->getViewData()['steps'];
    };
    $this->dismissedAt = fn (User $user, ?Organization $organization = null) => OrganizationUser::query()
        ->where('organization_id', ($organization ?? $this->acme)->id)
        ->where('user_id', $user->id)
        ->value('checklist_dismissed_at');
    $this->dismiss = function (User $user, ?Organization $organization = null) {
        $this->actingAs($user);
        Filament::setTenant($organization ?? $this->acme);

        return Livewire::test(GettingStartedWidget::class)->callAction('dismiss');
    };
});

afterEach(function () {
    Filament::setTenant(null);
});

test('a fresh organization shows the four steps unticked', function () {
    ($this->openAs)($this->member)
        ->assertOk()
        ->assertSeeLivewire(GettingStartedWidget::class)
        ->assertSee('Get started')
        ->assertSee('0 '.GETTING_STARTED_DESCRIPTION, false)
        ->assertSee('Add a contact')
        ->assertSee('href="'.ContactResource::getUrl('create').'"', false)
        ->assertSee('href="'.DealResource::getUrl('create').'"', false)
        ->assertSee('href="'.TaskResource::getUrl('create').'"', false)
        ->assertSee('href="'.InvoiceResource::getUrl('create').'"', false);
});

test('the card sits above the tabs of the dashboard', function () {
    $html = ($this->openAs)($this->member)->getContent();

    expect(strpos($html, 'Get started'))->toBeLessThan(strpos($html, 'Data overview'));
});

test('creating a contact ticks the first step on the next load', function () {
    ($this->recordsOf['contact'])($this->acme);

    $page = ($this->openAs)($this->member)->assertOk();
    $page->assertSee('1 '.GETTING_STARTED_DESCRIPTION, false)
        ->assertSee('(done)')
        ->assertDontSee('href="'.ContactResource::getUrl('create').'"', false)
        ->assertSee('href="'.DealResource::getUrl('create').'"', false);

    expect(substr_count($page->getContent(), '(done)'))->toBe(1);
});

test('each kind of record ticks its own step', function (string $record, string $step) {
    ($this->recordsOf[$record])($this->acme);

    $done = collect(($this->steps)($this->member))->where('done', true)->pluck('label')->all();

    expect($done)->toBe([$step]);
})->with([
    'contact' => ['contact', 'Add a contact'],
    'deal' => ['deal', 'Create a deal'],
    'task' => ['task', 'Add a task'],
    'invoice' => ['invoice', 'Create an invoice'],
]);

test('a done step is no link and an undone step links to its create page', function () {
    ($this->recordsOf['deal'])($this->acme);

    expect(($this->steps)($this->member))->toBe([
        ['label' => 'Add a contact', 'url' => ContactResource::getUrl('create'), 'done' => false],
        ['label' => 'Create a deal', 'url' => null, 'done' => true],
        ['label' => 'Add a task', 'url' => TaskResource::getUrl('create'), 'done' => false],
        ['label' => 'Create an invoice', 'url' => InvoiceResource::getUrl('create'), 'done' => false],
    ]);
});

test('all four done hides the widget', function () {
    foreach ($this->recordsOf as $make) {
        $make($this->acme);
    }

    ($this->openAs)($this->owner)
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class)
        ->assertDontSeeLivewire(GettingStartedWidget::class);

    expect(GettingStartedWidget::canView())->toBeFalse()
        ->and(GettingStartedChecklist::forCurrentUser()->isComplete())->toBeTrue();
});

test('three of four done still shows the widget', function () {
    foreach (['contact', 'deal', 'task'] as $record) {
        ($this->recordsOf[$record])($this->acme);
    }

    ($this->openAs)($this->owner)
        ->assertSeeLivewire(GettingStartedWidget::class)
        ->assertSee('3 '.GETTING_STARTED_DESCRIPTION, false);
});

test('soft-deleted records do not tick', function () {
    ($this->recordsOf['contact'])($this->acme)->delete();
    ($this->recordsOf['task'])($this->acme)->delete();

    ($this->openAs)($this->member)->assertSee('0 '.GETTING_STARTED_DESCRIPTION, false);

    expect(Contact::withTrashed()->count())->toBe(1)
        ->and(Task::withTrashed()->count())->toBe(1);
});

test("another organization's records never tick", function () {
    foreach ($this->recordsOf as $make) {
        $make($this->globex);
    }

    ($this->openAs)($this->owner)
        ->assertSeeLivewire(GettingStartedWidget::class)
        ->assertSee('0 '.GETTING_STARTED_DESCRIPTION, false);
});

test("another organization's records never tick any step of the widget", function () {
    foreach ($this->recordsOf as $make) {
        $make($this->globex);
    }

    $steps = ($this->steps)($this->owner);

    expect(collect($steps)->pluck('done')->all())->toBe([false, false, false, false]);
});

test('the dismiss action is offered to a member and hidden from a viewer', function () {
    $this->actingAs($this->member);
    Filament::setTenant($this->acme);
    Livewire::test(GettingStartedWidget::class)->assertActionVisible('dismiss');

    $this->actingAs($this->viewer);
    Livewire::test(GettingStartedWidget::class)->assertActionHidden('dismiss');
});

test('who sees the card', function (string $who, bool $shown) {
    $response = ($this->openAs)($this->{$who})->assertOk();

    $shown
        ? $response->assertSeeLivewire(GettingStartedWidget::class)
        : $response->assertDontSeeLivewire(GettingStartedWidget::class);

    expect(GettingStartedWidget::canView())->toBe($shown);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'member' => ['member', true],
    'viewer' => ['viewer', false],
]);

test('a viewer cannot dismiss through a forged Livewire call', function () {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->acme);

    $component = Livewire::test(GettingStartedWidget::class);

    $component->call('mountAction', 'dismiss', [], [])->assertForbidden();
    Livewire::test(GettingStartedWidget::class)->call('callMountedAction')->assertForbidden();

    expect(($this->dismissedAt)($this->viewer))->toBeNull();
});

test('a viewer reaching the widget through a Livewire round trip is refused', function () {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->acme);

    $component = Livewire::test(GettingStartedWidget::class);

    $component->call('$refresh')->assertForbidden();
});

test('a user who is not a member of the organization cannot dismiss', function () {
    $stranger = User::factory()->onboardingCompleted()->create();
    $this->actingAs($stranger);
    Filament::setTenant($this->acme);

    Livewire::test(GettingStartedWidget::class)
        ->call('mountAction', 'dismiss', [], [])
        ->assertForbidden();

    expect(OrganizationUser::query()->whereNotNull('checklist_dismissed_at')->count())->toBe(0)
        ->and(GettingStartedChecklist::forCurrentUser())->toBeNull();
});

test('dismiss hides it for this user in this organization only', function () {
    $this->travelTo('2026-11-10 09:00:00');

    ($this->dismiss)($this->member);

    $component = ($this->dismiss)($this->admin);
    expect($component->get('isDismissed'))->toBeTrue();
    $component->assertDontSee('Add a contact');

    expect(($this->dismissedAt)($this->member)->toDateTimeString())->toBe('2026-11-10 09:00:00')
        ->and(($this->dismissedAt)($this->owner))->toBeNull()
        ->and(($this->dismissedAt)($this->viewer))->toBeNull();

    ($this->openAs)($this->member)->assertDontSeeLivewire(GettingStartedWidget::class);
    ($this->openAs)($this->owner)->assertSeeLivewire(GettingStartedWidget::class);
});

test('dismissing marks the component dismissed and renders an empty root', function () {
    $this->actingAs($this->member);
    Filament::setTenant($this->acme);

    Livewire::test(GettingStartedWidget::class)
        ->assertSee('Add a contact')
        ->callAction('dismiss')
        ->assertSet('isDismissed', true)
        ->assertDontSee('Get started')
        ->assertDontSee('Add a contact');
});

test('dismissing in one organization keeps it in another', function () {
    ($this->dismiss)($this->owner);

    expect(($this->dismissedAt)($this->owner))->not->toBeNull()
        ->and(($this->dismissedAt)($this->owner, $this->globex))->toBeNull();

    ($this->openAs)($this->owner, $this->globex)->assertSeeLivewire(GettingStartedWidget::class);
    ($this->openAs)($this->owner)->assertDontSeeLivewire(GettingStartedWidget::class);
});

test('a new member sees it even after others dismissed it', function () {
    ($this->dismiss)($this->owner);
    $newbie = ($this->userOf)(OrganizationRole::Member);

    ($this->openAs)($newbie)->assertSeeLivewire(GettingStartedWidget::class);
});

test('the checklist costs at most five queries and one once dismissed', function () {
    $this->actingAs($this->member);
    Filament::setTenant($this->acme);

    $count = function (): int {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        GettingStartedChecklist::forCurrentUser();

        return $queries;
    };

    expect($count())->toBeLessThanOrEqual(5);

    ($this->dismiss)($this->member);

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries++;
    });

    expect(GettingStartedChecklist::forCurrentUser())->toBeNull()
        ->and($queries)->toBe(1);
});

test('a viewer costs one query and no existence checks', function () {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->acme);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(GettingStartedChecklist::forCurrentUser())->toBeNull()
        ->and($queries)->toHaveCount(1);
});

test('there is no checklist without a signed-in user or a current organization', function () {
    expect(GettingStartedChecklist::forCurrentUser())->toBeNull();

    $this->actingAs($this->member);
    Filament::setTenant(null);

    expect(GettingStartedChecklist::forCurrentUser())->toBeNull();
});

test('the card prints only fixed labels and no raw output', function () {
    ($this->openAs)($this->member)->assertSee('Create an invoice');

    $view = file_get_contents(resource_path('views/filament/widgets/getting-started-widget.blade.php'));

    expect($view)->not->toContain('{!!');
});
