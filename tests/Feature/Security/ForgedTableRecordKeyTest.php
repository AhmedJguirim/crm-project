<?php

use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Widgets\DealForecastWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Livewire\Tenancy\InvitationsList;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => true]);

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->vip = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee']);
    $this->segment = Segment::factory()->for($this->org)->create();
    $this->log = fakeSecurityLog();
});

/**
 * @return list<array<string, mixed>>
 */
function tableRecordKeyLines(TestHandler $log): array
{
    return array_values(collect(securityLogEntries($log))->where('event', 'request.forged_payload')->all());
}

/**
 * @return array{0: class-string, 1: array<string, mixed>}
 */
function tableRecordKeyHost(string $host, object $test): array
{
    return match ($host) {
        'tags' => [ListTags::class, []],
        'contacts' => [ListContacts::class, []],
        'members' => [OrganizationMembers::class, []],
        'invitations' => [InvitationsList::class, []],
        'segment contacts' => [ContactsRelationManager::class, ['ownerRecord' => $test->segment, 'pageClass' => ViewSegment::class]],
        'tasks widget' => [MyTasksWidget::class, []],
        'forecast widget' => [DealForecastWidget::class, []],
        'pipeline' => [DealPipeline::class, []],
    };
}

test('a forged record key on a table action answers 419 and changes nothing', function (string $host, string $action, mixed $key) {
    [$class, $params] = tableRecordKeyHost($host, $this);
    $component = Livewire::test($class, $params);
    $name = $component->instance()->getName();
    $vip = $this->vip->fresh()->getAttributes();
    $ann = $this->ann->fresh()->getAttributes();

    $component->call('mountAction', $action, [], ['table' => true, 'recordKey' => $key])->assertStatus(419);

    $lines = tableRecordKeyLines($this->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toMatchArray(['guard' => 'table_record_key', 'component' => $name, 'member' => null])
        ->and($this->vip->fresh()->getAttributes())->toBe($vip)
        ->and($this->ann->fresh()->getAttributes())->toBe($ann)
        ->and(Contact::query()->count())->toBe(1);
})->with([
    'tag text' => ['tags', 'edit', 'abc'],
    'tag decimal' => ['tags', 'edit', '1.5'],
    'tag 20 digits' => ['tags', 'edit', '99999999999999999999'],
    'tag above bigint' => ['tags', 'edit', '9223372036854775808'],
    'tag zero' => ['tags', 'edit', '0'],
    'tag negative' => ['tags', 'edit', '-1'],
    'tag leading zeros' => ['tags', 'edit', '007'],
    'tag sql' => ['tags', 'edit', '1 OR 1=1'],
    'tag padded' => ['tags', 'edit', ' 5'],
    'tag array' => ['tags', 'edit', ['x']],
    'tag float' => ['tags', 'edit', 1.5],
    'tag bool' => ['tags', 'edit', true],
    'contact text' => ['contacts', 'delete', 'abc'],
    'contact array' => ['contacts', 'delete', ['x']],
    'member text' => ['members', 'changeRole', 'abc'],
    'invitation text' => ['invitations', 'revoke', 'abc'],
    'segment contact text' => ['segment contacts', 'viewContact', 'abc'],
]);

test('other entry points to the record lookup refuse the same key', function (string $host, string $method, array $arguments) {
    [$class, $params] = tableRecordKeyHost($host, $this);

    Livewire::test($class, $params)->call($method, ...$arguments)->assertStatus(419);

    $lines = tableRecordKeyLines($this->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['guard'])->toBe('table_record_key')
        ->and($lines[0]['context']['member'])->toBeNull();
})->with([
    'column action' => ['contacts', 'callTableColumnAction', ['name', 'abc']],
]);

test('the record lookup refuses the same key on the PHP path', function (string $host, array $arguments) {
    [$class, $params] = tableRecordKeyHost($host, $this);
    $component = Livewire::test($class, $params);

    $thrown = null;

    try {
        $component->instance()->getTableRecord(...$arguments);
    } catch (HttpException $e) {
        $thrown = $e;
    }

    $lines = tableRecordKeyLines($this->log);

    expect($thrown)->toBeInstanceOf(HttpException::class)
        ->and($thrown->getStatusCode())->toBe(419)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context']['guard'])->toBe('table_record_key')
        ->and($lines[0]['context']['member'])->toBeNull();
})->with([
    'contacts text key' => ['contacts', ['abc']],
    'contacts array key' => ['contacts', [['x']]],
    'widget text key' => ['tasks widget', ['abc']],
    'array-record widget array key' => ['forecast widget', [['x']]],
    'board text key' => ['pipeline', ['abc']],
]);

test('a well-formed but unknown key still unmounts quietly', function () {
    Livewire::test(ListTags::class)
        ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => '9223372036854775807'])
        ->assertStatus(200)
        ->assertSet('mountedActions', []);

    expect(tableRecordKeyLines($this->log))->toBe([]);
});

test('an integer key is accepted like its string', function () {
    Livewire::test(ListTags::class)
        ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => $this->vip->id])
        ->assertStatus(200);

    expect(tableRecordKeyLines($this->log))->toBe([]);
});

test('a real id of another organization gets nothing', function () {
    $other = Organization::factory()->create();
    $theirs = Tag::factory()->create(['organization_id' => $other->id, 'name' => 'VIP']);
    $before = $theirs->fresh()->getAttributes();

    Livewire::test(ListTags::class)
        ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $theirs->id])
        ->assertStatus(200)
        ->assertSet('mountedActions', []);

    expect(tableRecordKeyLines($this->log))->toBe([])
        ->and($theirs->fresh()->getAttributes())->toBe($before);
});

test('a real record still mounts its action', function () {
    $component = Livewire::test(ListTags::class)
        ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $this->vip->id])
        ->assertStatus(200);

    expect($component->get('mountedActions'))->toHaveCount(1)
        ->and($component->instance()->getMountedAction()->getRecord()->is($this->vip))->toBeTrue()
        ->and(tableRecordKeyLines($this->log))->toBe([]);
});

test('a table without a query accepts a non-numeric string key', function () {
    $component = Livewire::test(DealForecastWidget::class);

    expect(fn () => $component->instance()->getTableRecord('abc'))->not->toThrow(HttpException::class)
        ->and(tableRecordKeyLines($this->log))->toBe([]);
});

test('a missing key is passed through and never logged', function () {
    $component = Livewire::test(ListTags::class);

    expect($component->instance()->getTableRecord(null))->toBeNull()
        ->and(tableRecordKeyLines($this->log))->toBe([]);
});
