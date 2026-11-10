<?php

use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ExportActivitiesJob;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->org->update(['timezone' => 'Pacific/Auckland']);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->activity = fn (string $subject, string $occurredAt, array $attributes = []): Activity => Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->owner->id,
        'subject' => $subject,
        'occurred_at' => $occurredAt,
        ...$attributes,
    ]);

    $this->deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->owner->id]);

    $this->before = ($this->activity)('Before', '2026-11-01 10:59:59');
    $this->first = ($this->activity)('First', '2026-11-01 11:00:00');
    $this->last = Activity::factory()->onlyOnDeal($this->deal)->create(['user_id' => $this->owner->id, 'subject' => 'Last', 'occurred_at' => '2026-11-03 10:59:59']);
    $this->after = ($this->activity)('After', '2026-11-03 11:00:00');

    $this->pushedJob = function (): array {
        return Queue::pushed(ExportActivitiesJob::class)
            ->map(fn (ExportActivitiesJob $job): array => (fn (): array => [
                'ids' => $this->activityIds,
                'organization' => $this->organizationId,
                'user' => $this->userId,
                'format' => $this->format,
                'name' => $this->downloadName,
            ])->call($job))
            ->values()
            ->all();
    };

    $this->export = fn (array $data = []) => Livewire::test(ListContacts::class)
        ->callAction('exportActivities', ['format' => ExportFormat::Xlsx->value, ...$data]);
});

describe('an admin', function () {
    beforeEach(function () {
        $this->admin = ($this->actAs)(OrganizationRole::Admin);
    });

    it('exports the activities of a range, local days inclusive', function () {
        ($this->export)(['from' => '2026-11-02', 'until' => '2026-11-03'])->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->first->id, $this->last->id])
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['format'])->toBe('xlsx');
    });

    it('swaps a reversed range', function () {
        ($this->export)(['from' => '2026-11-03', 'until' => '2026-11-02']);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->first->id, $this->last->id]);
    });

    it('has no limit on an open side', function (?string $from, ?string $until, array $names) {
        ($this->export)(['from' => $from, 'until' => $until]);

        $ids = ($this->pushedJob)()[0]['ids'];

        expect($ids)->toBe(array_map(fn (string $name): int => $this->{$name}->id, $names));
    })->with([
        'both open' => [null, null, ['before', 'first', 'last', 'after']],
        'only from' => ['2026-11-02', null, ['first', 'last', 'after']],
        'only until' => [null, '2026-11-03', ['before', 'first', 'last']],
    ]);

    it('orders by occurred_at then by id', function () {
        $twins = collect(range(1, 40))->map(fn (int $number): Activity => ($this->activity)("Twin {$number}", '2026-11-01 11:00:00'));
        $earlier = ($this->activity)('Earlier', '2026-11-01 10:00:00');
        $this->first->update(['notes' => 'rewritten, so its row now sits after the twins']);
        $twins->reverse()->each->update(['notes' => 'rewritten']);

        ($this->export)(['from' => '2026-11-02', 'until' => '2026-11-02']);

        $expected = collect([$this->first->id, ...$twins->pluck('id')->all()])->sort()->values()->all();

        expect(($this->pushedJob)()[0]['ids'])->toBe($expected)
            ->and($earlier->id)->not->toBeIn($expected);
    });

    it('ignores the tab, the search and the filters of the contacts list', function () {
        Livewire::test(ListContacts::class)
            ->set('activeTab', 'mine')
            ->searchTable('nobody matches this')
            ->callAction('exportActivities', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->before->id, $this->first->id, $this->last->id, $this->after->id]);
    });

    it('includes the activities of a deleted contact and exports none of another organization', function () {
        $this->contact->delete();
        $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
        Activity::factory()->create(['organization_id' => $other->id, 'occurred_at' => '2026-11-02 00:00:00']);

        ($this->export)();

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->before->id, $this->first->id, $this->last->id, $this->after->id]);
    });

    it('names the file with the local date of the organization', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        ($this->export)(['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['name'])->toBe('activities-2026-10-07.csv');
    });

    it('warns when there is nothing to export', function () {
        ($this->export)(['from' => '2030-01-01', 'until' => '2030-01-31'])->assertNotified('Nothing to export.');

        Queue::assertNotPushed(ExportActivitiesJob::class);
    });

    it('refuses a forged or out-of-range date without a server error', function (string $field, mixed $value) {
        Livewire::test(ListContacts::class)
            ->mountAction('exportActivities')
            ->setActionData(['format' => ExportFormat::Xlsx->value, $field => $value])
            ->callMountedAction()
            ->assertHasActionErrors([$field]);

        Queue::assertNotPushed(ExportActivitiesJob::class);
    })->with([
        'junk from' => ['from', 'not a date'],
        'until before 1900' => ['until', '1899-12-31'],
        'from after 2100' => ['from', '2101-01-01'],
        'from before 1900' => ['from', '1899-12-31'],
        'until after 2100' => ['until', '2101-01-01'],
        'array until' => ['until', ['2026-11-02']],
    ]);

    it('defaults to Excel and refuses another format', function () {
        Livewire::test(ListContacts::class)
            ->mountAction('exportActivities')
            ->assertSchemaStateSet(['format' => ExportFormat::Xlsx])
            ->setActionData(['format' => 'pdf'])
            ->callMountedAction()
            ->assertHasActionErrors(['format']);

        Queue::assertNotPushed(ExportActivitiesJob::class);
    });

    it('checks the activity policy and not the contact policy', function () {
        $checked = [];
        Gate::before(function ($user, string $ability, array $arguments) use (&$checked) {
            if ($ability === 'export') {
                $checked[] = $arguments[0];
            }

            return null;
        });

        $page = Livewire::test(ListContacts::class);
        $checked = [];

        $page->mountAction('exportActivities')->assertActionMounted('exportActivities');

        expect($checked)->not->toBeEmpty()
            ->and(array_values(array_unique($checked)))->toBe([Activity::class]);
    });

    it('writes a file with the rows of the range', function () {
        Storage::fake('local');

        ($this->export)(['from' => '2026-11-02', 'until' => '2026-11-03', 'format' => ExportFormat::Csv->value]);

        Queue::pushed(ExportActivitiesJob::class)->sole()->handle();

        $file = Storage::disk('local')->files('exports')[0];
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file), 'csv')->getRows()->all();

        expect($rows)->toHaveCount(2)
            ->and(array_column($rows, 'subject'))->toBe(['First', 'Last'])
            ->and(array_keys($rows[0]))->toContain('occurred at (Pacific/Auckland)');
    });
});

describe('the owner', function () {
    it('exports too', function () {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);

        Livewire::test(ListContacts::class)
            ->assertActionVisible('exportActivities')
            ->callAction('exportActivities', ['format' => ExportFormat::Xlsx->value]);

        Queue::assertPushed(ExportActivitiesJob::class, 1);
    });
});

describe('a member or a viewer', function () {
    it('does not see the action', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListContacts::class)->assertActionHidden('exportActivities');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('queues nothing when calling the action anyway', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListContacts::class)
            ->call('mountAction', 'exportActivities', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Queue::assertNotPushed(ExportActivitiesJob::class);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
