<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager;
use App\Filament\Widgets\MyTasksWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Segment;
use App\Models\User;
use App\Support\Livewire\RefuseInternalMethodCalls;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    config(['app.debug' => true]);

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'owner-bug90@acme.test']);
    $this->org = $this->owner->personalOrganization();
    $this->viewer = User::factory()->onboardingCompleted()->create(['email' => 'vera-bug90@acme.test']);
    $this->org->members()->attach($this->viewer->id, ['role' => OrganizationRole::Viewer->value]);

    $this->deal = Deal::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->owner->id, 'title' => 'Big Deal']);
    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee']);
    $this->segment = Segment::factory()->for($this->org)->create();

    $this->actingAs($this->viewer);
    Filament::setTenant($this->org);
    $this->log = fakeSecurityLog();
});

/**
 * @return list<array<string, mixed>>
 */
function internalMethodLines(TestHandler $log): array
{
    return array_values(collect(securityLogEntries($log))->where('event', 'request.forged_payload')->all());
}

/**
 * @return array{0: class-string, 1: array<string, mixed>}
 */
function internalMethodHost(string $host, object $test): array
{
    return match ($host) {
        'deals' => [ListDeals::class, []],
        'deals selected' => [ListDeals::class, ['selectedTableRecords' => [(string) $test->deal->id]]],
        'segment contacts' => [ContactsRelationManager::class, ['ownerRecord' => $test->segment, 'pageClass' => ViewSegment::class]],
        'view contact' => [ViewContact::class, ['record' => $test->ann->id]],
        'edit contact' => [EditContact::class, ['record' => $test->ann->id]],
        'rule engine' => [SegmentRuleEngine::class, ['record' => $test->segment->getRouteKey()]],
        'tasks widget' => [MyTasksWidget::class, []],
    };
}

test('a viewer reading a deal row from the console gets 419, not the row', function () {
    $component = Livewire::test(ListDeals::class);
    $name = $component->instance()->getName();
    $component->call('getTableRecord', (string) $this->deal->id)->assertStatus(419);

    $returns = json_encode($component->effects['returns'] ?? []);
    $lines = internalMethodLines($this->log);

    expect($returns)->not->toContain('creator')
        ->and($returns)->not->toContain('email_verified_at')
        ->and($returns)->not->toContain('terms_accepted_at')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toMatchArray(['guard' => 'internal_method', 'component' => $name, 'member' => 'getTableRecord']);
});

test('every record getter is refused from the browser', function (string $host, string $method, array $arguments) {
    [$class, $params] = internalMethodHost($host, $this);

    Livewire::test($class, $params)->call($method, ...$arguments)->assertStatus(419);

    $lines = internalMethodLines($this->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['guard'])->toBe('internal_method')
        ->and($lines[0]['context']['member'])->toBe($method);
})->with([
    'table records' => ['deals', 'getTableRecords', []],
    'selected records' => ['deals selected', 'getSelectedTableRecords', []],
    'mounted action record' => ['deals', 'getMountedTableActionRecord', []],
    'owner record' => ['segment contacts', 'getOwnerRecord', []],
    'page record' => ['view contact', 'getRecord', []],
    'widget record' => ['tasks widget', 'getTableRecord', ['1']],
]);

test('a viewer reading the contact row through getWidgetData gets 419, not the row', function () {
    $component = Livewire::test(ViewContact::class, ['record' => $this->ann->id]);
    $name = $component->instance()->getName();
    $component->call('getWidgetData')->assertStatus(419);

    $returns = json_encode($component->effects['returns'] ?? []);
    $lines = internalMethodLines($this->log);

    expect($returns)->not->toContain('custom_field_values')
        ->and($returns)->not->toContain('Ann Lee')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toMatchArray(['guard' => 'internal_method', 'component' => $name, 'member' => 'getWidgetData']);
});

test('each new name is refused', function (string $as, string $host, string $method) {
    $this->actingAs($as === 'owner' ? $this->owner : $this->viewer);

    [$class, $params] = internalMethodHost($host, $this);

    $component = Livewire::test($class, $params)->call($method)->assertStatus(419);

    $returns = json_encode($component->effects['returns'] ?? []);
    $lines = internalMethodLines($this->log);

    expect($returns)->not->toContain('Ann Lee')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context']['guard'])->toBe('internal_method')
        ->and($lines[0]['context']['member'])->toBe($method);
})->with([
    'viewer parent record' => ['viewer', 'view contact', 'getParentRecord'],
    'owner widget data' => ['owner', 'edit contact', 'getWidgetData'],
    'owner segment' => ['owner', 'rule engine', 'segment'],
]);

test('the owner is refused the same way', function () {
    $this->actingAs($this->owner);

    Livewire::test(ListDeals::class)->call('getTableRecord', (string) $this->deal->id)->assertStatus(419);

    expect(internalMethodLines($this->log))->toHaveCount(1);
});

test('the methods Filament JS calls still work', function () {
    Livewire::test(ListDeals::class)->call('getAllSelectableTableRecordKeys')->assertStatus(200);

    expect(internalMethodLines($this->log))->toBe([]);

    $this->actingAs($this->owner);

    $component = Livewire::test(ListDeals::class)
        ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $this->deal->id])
        ->assertStatus(200);

    expect($component->get('mountedActions'))->toHaveCount(1)
        ->and($component->instance()->getMountedAction()->getRecord()->is($this->deal))->toBeTrue()
        ->and(internalMethodLines($this->log))->toBe([]);
});

test('the PHP path is untouched', function () {
    $record = Livewire::test(ListDeals::class)->instance()->getTableRecord((string) $this->deal->id);
    $component = Livewire::test(ListContacts::class)->instance();

    $widgetData = Livewire::test(ViewContact::class, ['record' => $this->ann->id])->instance()->getWidgetData();

    expect($record->is($this->deal))->toBeTrue()
        ->and($component->getTableRecords()->pluck('id')->all())->toContain($this->ann->id)
        ->and($widgetData['record']->name)->toBe('Ann Lee')
        ->and(internalMethodLines($this->log))->toBe([]);

    $this->actingAs($this->owner);

    expect(Livewire::test(SegmentRuleEngine::class, ['record' => $this->segment->getRouteKey()])->instance()->segment()->is($this->segment))->toBeTrue()
        ->and(internalMethodLines($this->log))->toBe([]);
});

test('a different-case method name never reaches the getter', function () {
    $component = Livewire::test(ListDeals::class);

    expect(fn () => $component->call('gettablerecord', (string) $this->deal->id))->toThrow(Exception::class);
    expect(internalMethodLines($this->log))->toBe([]);
});

test('the denied names are still real methods', function () {
    $classes = [ListDeals::class, ContactsRelationManager::class, ViewContact::class, EditContact::class, SegmentRuleEngine::class];

    foreach (RefuseInternalMethodCalls::INTERNAL_METHODS as $name) {
        $found = collect($classes)->contains(fn (string $class): bool => method_exists($class, $name) && (new ReflectionMethod($class, $name))->isPublic());

        expect($found)->toBeTrue("{$name} is no longer a public method");
    }
});
