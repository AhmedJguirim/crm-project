<?php

use App\Enums\TaskType;
use App\Filament\Pages\Auth\Register;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Support\StateCasts\RecordKeyIsReadable;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    config(['app.debug' => false]);

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'alice@acme.test']);
    $this->org = $this->alice->personalOrganization();
    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->vip = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $this->log = fakeSecurityLog();
});

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function forgedValueLines(TestHandler $log, string $event = 'request.forged_value'): array
{
    return array_values(collect(securityLogEntries($log))->where('event', $event)->all());
}

function forgedNameOnCreateContact(): Testable
{
    return Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Jane Roe', 'email' => 'jane.roe@example.test'])
        ->set('data.name', ['x'])
        ->call('create');
}

test('an array forged into a text field of a create page is logged with guard and component only', function () {
    $name = Livewire::test(CreateContact::class)->instance()->getName();

    $page = forgedNameOnCreateContact();

    expect($page->errors()->get('data.name'))->toBe(['The name field must be a single value.'])
        ->and(Contact::query()->where('email', 'jane.roe@example.test')->exists())->toBeFalse();

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forged_value')
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['context'])->toBe([
            'guard' => 'single_value_field',
            'component' => $name,
            'user_id' => $this->alice->id,
            'organization_id' => $this->org->id,
            'ip' => '127.0.0.1',
        ]);
});

test('a padded record id the integer rule lets through is logged', function (Closure $shape) {
    $name = Livewire::test(CreateTask::class)->instance()->getName();

    $page = Livewire::test(CreateTask::class)
        ->fillForm(['title' => 'Padded', 'type' => TaskType::FollowUp])
        ->set('data.contact_id', $shape($this->jane->id))
        ->call('create');

    expect($page->errors()->get('data.contact_id')[0] ?? '')->toContain('must be an integer')
        ->and(Task::query()->where('title', 'Padded')->exists())->toBeFalse();

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forged_value')
        ->and($entries[0]['context'])->toBe([
            'guard' => 'record_key',
            'component' => $name,
            'user_id' => $this->alice->id,
            'organization_id' => $this->org->id,
            'ip' => '127.0.0.1',
        ]);
})->with([
    'leading space' => [fn (int $id): string => ' '.$id],
    'plus sign' => [fn (int $id): string => '+'.$id],
    'trailing newline' => [fn (int $id): string => $id."\n"],
]);

test('two forged items in a multi-select write two lines', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Padded', 'email' => 'padded@example.test'])
        ->set('data.tags', [' '.$this->vip->id, '+'.$this->vip->id])
        ->call('create');

    $lines = forgedValueLines($this->log);

    expect(Contact::query()->where('email', 'padded@example.test')->exists())->toBeFalse()
        ->and($lines)->toHaveCount(2)
        ->and(collect($lines)->pluck('context.guard')->unique()->all())->toBe(['record_key']);
});

test('a guest forging the registration email is keyed by ip and the address is not logged', function () {
    auth()->logout();
    Filament::setTenant(null);
    $this->log->clear();

    $name = Livewire::test(Register::class)->instance()->getName();

    $page = Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Eve Stone',
            'password' => 'password',
            'passwordConfirmation' => 'password',
            'accept_terms' => true,
        ])
        ->set('data.email', ['eve@example.com'])
        ->call('register');

    expect($page->errors()->get('data.email'))->toBe(['The email address field must be a single value.'])
        ->and(User::query()->where('email', 'eve@example.com')->exists())->toBeFalse();

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['context'])->toBe([
            'guard' => 'single_value_field',
            'component' => $name,
            'user_id' => null,
            'organization_id' => null,
            'ip' => '127.0.0.1',
        ])
        ->and(json_encode($entries))->not->toContain('eve@example.com')->not->toContain('data.email')
        ->and(RateLimiter::attempts('security-log-refusals:ip:127.0.0.1'))->toBe(1);
});

test('the context never carries the forged value or the attribute', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Jane Roe', 'email' => 'jane.roe@example.test'])
        ->set('data.name', ['<script>alert(1)</script>', 'ann.lee@example.test'])
        ->call('create');

    $entries = securityLogEntries($this->log);
    $json = json_encode($entries);

    expect($entries)->not->toBeEmpty()
        ->and($json)->not->toContain('<script>')->not->toContain('ann.lee@example.test')->not->toContain('data.name');

    foreach ($entries as $entry) {
        expect(array_keys($entry['context']))->toBe(['guard', 'component', 'user_id', 'organization_id', 'ip']);
    }
});

test('the cap is shared with forged payloads and 403s', function () {
    foreach (range(1, 10) as $ignored) {
        RateLimiter::hit('security-log-refusals:user:'.$this->alice->id, 60);
    }

    $page = forgedNameOnCreateContact();

    expect($page->errors()->get('data.name'))->toBe(['The name field must be a single value.'])
        ->and(forgedValueLines($this->log))->toBeEmpty()
        ->and(forgedValueLines($this->log, 'request.refusals_capped'))->toHaveCount(1);
});

test('a loop of forged submits is capped', function () {
    foreach (range(1, 12) as $ignored) {
        $page = forgedNameOnCreateContact();

        expect($page->errors()->get('data.name'))->toBe(['The name field must be a single value.']);
    }

    expect(forgedValueLines($this->log))->toHaveCount(10)
        ->and(forgedValueLines($this->log, 'request.refusals_capped'))->toHaveCount(1);
});

test('a legitimate save and a normal validation error write nothing', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Jane Roe', 'email' => 'jane.roe@example.test', 'tags' => [$this->vip->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => '', 'email' => 'blank@example.test'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Contact::query()->where('email', 'jane.roe@example.test')->exists())->toBeTrue()
        ->and(securityLogEntries($this->log))->toBeEmpty();
});

test('outside livewire the component is null', function () {
    $validator = Validator::make(['contact_id' => ' 5'], ['contact_id' => ['bail', 'integer', new RecordKeyIsReadable]]);

    $failed = $validator->fails();
    $entries = securityLogEntries($this->log);

    expect($failed)->toBeTrue()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]['context']['guard'])->toBe('record_key')
        ->and($entries[0]['context']['component'])->toBeNull();
});

test('a cache failure still logs the line and still refuses the value', function () {
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('cache down'));

    $page = forgedNameOnCreateContact();

    expect($page->errors()->get('data.name'))->toBe(['The name field must be a single value.'])
        ->and(Contact::query()->where('email', 'jane.roe@example.test')->exists())->toBeFalse()
        ->and(forgedValueLines($this->log))->toHaveCount(1);
});
