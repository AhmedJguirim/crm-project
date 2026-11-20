<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Contact;
use App\Models\User;
use App\Support\Security\SecurityLog;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

class ForgedPayloadLogProbeComponent extends Component
{
    #[On('probe-selected')]
    public function select(int $id): void {}

    public function bodyCallsInternalWrongly(): void
    {
        strlen([]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

beforeEach(function () {
    config(['app.debug' => false]);

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann.lee@example.test']);
    $this->log = fakeSecurityLog();
});

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function forgedPayloadLines(TestHandler $log, string $event = 'request.forged_payload'): array
{
    return array_values(collect(securityLogEntries($log))->where('event', $event)->all());
}

test('each guard writes one line with its guard and member', function (string $guard, ?string $member, Closure $send, string $componentClass) {
    $name = Livewire::test($componentClass, $componentClass === ContactActivityFeed::class ? ['record' => $this->ann] : [])->instance()->getName();

    $send($this)->assertStatus(419);

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forged_payload')
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['context'])->toBe([
            'guard' => $guard,
            'component' => $name,
            'member' => $member,
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'ip' => '127.0.0.1',
        ]);
})->with([
    'sub key below a string' => ['sub_key_update', 'tableSearch', fn () => Livewire::test(ListContacts::class)->set('tableSearch.0', 'x'), ListContacts::class],
    'leading-digit sub key below a string' => ['sub_key_update', 'tableSearch', fn () => Livewire::test(ListContacts::class)->set('tableSearch.0abc', 'x'), ListContacts::class],
    'sub key below a model' => ['sub_key_update', 'record', fn ($t) => Livewire::test(ContactActivityFeed::class, ['record' => $t->ann])->set('record.name', 'x'), ContactActivityFeed::class],
    'array sent to a string property' => ['mistyped_property', 'email', fn () => Livewire::test('pages::settings.profile')->set('email', ['x@example.com']), 'pages::settings.profile'],
    'wrong-typed argument' => ['mistyped_argument', 'sortTable', fn () => Livewire::test(ListContacts::class)->call('sortTable', ['x']), ListContacts::class],
    'missing argument' => ['missing_argument', null, fn () => Livewire::test(ListContacts::class)->call('mountAction'), ListContacts::class],
    'malformed dispatch' => ['malformed_dispatch', null, fn () => Livewire::test(ForgedPayloadLogProbeComponent::class)->call('__dispatch', 'no-such-event', []), ForgedPayloadLogProbeComponent::class],
    'forged table record key' => ['table_record_key', null, fn () => Livewire::test(ListContacts::class)->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => 'abc']), ListContacts::class],
    'forged pagination argument' => ['pagination_argument', 'gotoPage', fn () => Livewire::test(ListContacts::class)->call('gotoPage', ['x']), ListContacts::class],
    'malformed dispatch payload' => ['malformed_dispatch', null, fn () => Livewire::test(ForgedPayloadLogProbeComponent::class)->call('__dispatch', 'probe-selected', 'x'), ForgedPayloadLogProbeComponent::class],
]);

test('a tampered snapshot over raw http writes one checksum line', function () {
    $snapshot = Livewire::test(ListContacts::class)->snapshot;
    $snapshot['data']['tableSearch'] = 'x';

    $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => []]],
    ], ['X-Livewire' => 'true'])->assertStatus(419);

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forged_payload')
        ->and($entries[0]['level'])->toBe('warning')
        ->and(Arr::except($entries[0]['context'], ['organization_id']))->toBe([
            'guard' => 'checksum',
            'component' => null,
            'member' => null,
            'user_id' => $this->owner->id,
            'ip' => '127.0.0.1',
        ]);
});

test('a guest tampered snapshot is logged by ip', function () {
    $snapshot = Livewire::test(ListContacts::class)->snapshot;
    $snapshot['data']['tableSearch'] = 'x';

    auth()->logout();
    $this->log = fakeSecurityLog();

    $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => []]],
    ], ['X-Livewire' => 'true'])->assertStatus(419);

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forged_payload')
        ->and($entries[0]['context']['guard'])->toBe('checksum')
        ->and($entries[0]['context']['user_id'])->toBeNull()
        ->and($entries[0]['context']['ip'])->toBe('127.0.0.1');
});

test('a legitimate update and call write nothing', function () {
    Livewire::test(ListContacts::class)->set('tableSearch', 'Ann')->assertStatus(200);
    Livewire::test(ListContacts::class)->call('sortTable', 'name')->assertStatus(200);
    Livewire::test(ForgedPayloadLogProbeComponent::class)->call('__dispatch', 'probe-selected', ['id' => 5])->assertStatus(200);

    expect(securityLogEntries($this->log))->toBe([]);
});

test('an error the guard lets through writes nothing', function () {
    expect(fn () => Livewire::test(ForgedPayloadLogProbeComponent::class)->call('bodyCallsInternalWrongly'))->toThrow(TypeError::class);

    expect(forgedPayloadLines($this->log))->toBe([]);
});

test('the refusal is logged with debug on too', function () {
    config(['app.debug' => true]);

    Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);

    expect(forgedPayloadLines($this->log))->toHaveCount(1);
});

test('a forged path carrying a fake log line never reaches the log', function () {
    $name = Livewire::test(ListContacts::class)->instance()->getName();
    $snapshot = json_encode(Livewire::test(ListContacts::class)->snapshot);
    $payload = "tableSearch.x\n[2026-11-18 10:00:00] testing.INFO: auth.login {\"user_id\":1}";

    $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => [$payload => 'x'], 'calls' => []]],
    ], ['X-Livewire' => 'true'])->assertStatus(419);

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and(Arr::except($entries[0]['context'], ['organization_id']))->toBe([
            'guard' => 'sub_key_update',
            'component' => $name,
            'member' => 'tableSearch',
            'user_id' => $this->owner->id,
            'ip' => '127.0.0.1',
        ]);

    $encoded = json_encode($entries);

    expect($encoded)->not->toContain('auth.login')->not->toContain('\\n');
});

test('a malformed dispatch with a script name logs no part of it', function () {
    Livewire::test(ForgedPayloadLogProbeComponent::class)->call('__dispatch', '<script>alert(1)</script>', [])->assertStatus(419);

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['context']['member'])->toBeNull()
        ->and(json_encode($entries))->not->toContain('<script>')->not->toContain('script');
});

test('a mistyped argument or missing argument call logs no param', function () {
    Livewire::test(ListContacts::class)->call('sortTable', ['<script>x</script>'])->assertStatus(419);

    expect(json_encode(securityLogEntries($this->log)))->not->toContain('script');
});

test('one user flooding forged updates is capped at ten lines a minute', function () {
    $this->freezeTime();

    foreach (range(1, 12) as $ignored) {
        Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);
    }

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(11)
        ->and(forgedPayloadLines($this->log))->toHaveCount(10)
        ->and(forgedPayloadLines($this->log, 'request.refusals_capped'))->toHaveCount(1)
        ->and($entries[10]['event'])->toBe('request.refusals_capped')
        ->and($entries[10]['level'])->toBe('warning')
        ->and($entries[10]['context'])->toBe([
            'cap' => 10,
            'window_seconds' => 60,
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'ip' => '127.0.0.1',
        ]);

    $this->travel(61)->seconds();

    Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);

    expect(securityLogEntries($this->log))->toHaveCount(12);
});

test('a user flooding leading-digit forged paths is capped at ten lines a minute', function () {
    $this->freezeTime();

    foreach (range(1, 12) as $ignored) {
        Livewire::test(ListContacts::class)->set('tableSearch.0abc', 'x')->assertStatus(419);
    }

    expect(forgedPayloadLines($this->log))->toHaveCount(10)
        ->and(forgedPayloadLines($this->log, 'request.refusals_capped'))->toHaveCount(1)
        ->and(json_encode(securityLogEntries($this->log)))->not->toContain('0abc');
});

test('a leading-digit forged path of one component is logged against that component, not its neighbour in the request', function () {
    $contacts = json_encode(Livewire::test(ListContacts::class)->snapshot);
    $deals = Livewire::test(ListDeals::class);
    $dealsName = $deals->instance()->getName();

    $this->postJson(route('default-livewire.update'), [
        'components' => [
            ['snapshot' => $contacts, 'updates' => ['tableSearch' => 'Ann'], 'calls' => []],
            ['snapshot' => json_encode($deals->snapshot), 'updates' => ['activeTab.0abc' => 'x'], 'calls' => []],
        ],
    ], ['X-Livewire' => 'true'])->assertStatus(419);

    $lines = forgedPayloadLines($this->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['guard'])->toBe('sub_key_update')
        ->and($lines[0]['context']['component'])->toBe($dealsName)
        ->and($lines[0]['context']['member'])->toBe('activeTab');
});

test('the cap counts every refusal kind together', function () {
    $this->freezeTime();

    foreach (range(1, 6) as $ignored) {
        Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);
        Livewire::test(ListContacts::class)->call('sortTable', ['x'])->assertStatus(419);
    }

    expect(forgedPayloadLines($this->log))->toHaveCount(10)
        ->and(forgedPayloadLines($this->log, 'request.refusals_capped'))->toHaveCount(1);
});

test('another user lines are not swallowed by the flooder cap', function () {
    $this->freezeTime();

    foreach (range(1, 12) as $ignored) {
        Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);
    }

    $bob = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($bob->id, ['role' => OrganizationRole::Member->value]);
    $this->actingAs($bob);
    Filament::setTenant($this->org);

    Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);

    $lines = forgedPayloadLines($this->log);

    expect($lines)->toHaveCount(11)
        ->and(Arr::last($lines)['context']['user_id'])->toBe($bob->id);
});

test('a guest is capped by ip', function () {
    $this->freezeTime();

    auth()->logout();
    $this->log = fakeSecurityLog();

    foreach (range(1, 12) as $ignored) {
        SecurityLog::refusal('request.forged_payload', ['guard' => 'checksum', 'component' => null, 'member' => null]);
    }

    expect(forgedPayloadLines($this->log))->toHaveCount(10)
        ->and(forgedPayloadLines($this->log, 'request.refusals_capped'))->toHaveCount(1)
        ->and(forgedPayloadLines($this->log, 'request.refusals_capped')[0]['context']['user_id'])->toBeNull();
});

test('a broken cache never turns the refusal into a 500', function () {
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('cache down'));

    Livewire::test(ListContacts::class)->set('tableSearch.0', 'x')->assertStatus(419);

    expect(forgedPayloadLines($this->log))->toHaveCount(1);
});

test('every sub key refusal site logs the first path segment as the member', function (string $path, string $property) {
    $component = new class extends Component
    {
        public string $title;

        public array $rows = ['a' => 'text'];

        public array $items = [];

        public function mount(Contact $contact): void
        {
            $this->items = ['contact' => $contact];
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };

    $test = Livewire::test($component::class, ['contact' => $this->ann]);
    $name = $test->instance()->getName();

    $test->set($path, 'x')->assertStatus(419);

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['context'])->toBe([
            'guard' => 'sub_key_update',
            'component' => $name,
            'member' => $property,
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'ip' => '127.0.0.1',
        ]);
})->with([
    'uninitialized typed property' => ['title.0', 'title'],
    'scalar nested in an array' => ['rows.a.b', 'rows'],
    'model nested in an array' => ['items.contact.name', 'items'],
]);
