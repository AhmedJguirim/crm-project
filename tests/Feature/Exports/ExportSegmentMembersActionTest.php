<?php

use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Jobs\ExportContactsJob;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
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

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann', 'email' => 'ann@x.test']);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob', 'email' => 'bob@x.test']);
    $this->cid = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Cid', 'email' => 'cid@x.test']);

    $this->segment = Segment::factory()->for($this->org)->published()->create(['name' => 'VIP']);
    $this->segment->contacts()->attach([$this->cid->id, $this->ann->id]);

    $this->page = fn (?Segment $segment = null) => Livewire::test(ViewSegment::class, ['record' => ($segment ?? $this->segment)->getRouteKey()]);

    $this->pushedJob = function (): array {
        return Queue::pushed(ExportContactsJob::class)
            ->map(fn (ExportContactsJob $job): array => (fn (): array => [
                'ids' => $this->contactIds,
                'organization' => $this->organizationId,
                'user' => $this->userId,
                'format' => $this->format,
                'name' => $this->downloadName,
            ])->call($job))
            ->values()
            ->all();
    };

    $this->refuses = function (): void {
        Queue::fake();
        ($this->page)()
            ->call('mountAction', 'exportMembers')
            ->assertActionNotMounted()
            ->call('callMountedAction');

        expect(($this->pushedJob)())->toBe([]);
    };
});

describe('an admin', function () {
    beforeEach(function () {
        Queue::fake();
        $this->admin = ($this->actAs)(OrganizationRole::Admin);
    });

    it('exports the members, by name, and not the other contacts', function () {
        ($this->page)()
            ->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->ann->id, $this->cid->id])
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['format'])->toBe('xlsx');
    });

    it('orders the members that share a name by id', function () {
        $twin = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann', 'email' => 'ann2@x.test']);
        $this->segment->contacts()->sync([$twin->id, $this->ann->id, $this->cid->id]);

        ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->ann->id, $twin->id, $this->cid->id]);
    });

    it('does not export a trashed member', function () {
        $this->cid->delete();

        ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->ann->id]);
    });

    it('does not export the members of a segment of another organization', function () {
        $other = Organization::factory()->create();
        $stranger = Contact::factory()->create(['organization_id' => $other->id, 'name' => 'Zed', 'email' => 'zed@x.test']);
        $otherSegment = Segment::factory()->for($other)->published()->create(['name' => 'VIP']);
        $otherSegment->contacts()->attach($stranger->id);

        ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->ann->id, $this->cid->id]);
    });

    it('warns when the segment has no member', function () {
        $empty = Segment::factory()->for($this->org)->published()->create(['name' => 'Empty']);

        ($this->page)($empty)
            ->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Nothing to export.');

        expect(($this->pushedJob)())->toBe([]);
    });

    it('names the file after the segment and the local date of the organization', function (string $name, string $file) {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));
        $this->segment->update(['name' => $name]);

        ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['name'])->toBe($file);
    })->with([
        'simple' => ['VIP', 'segment-vip-2026-10-07.xlsx'],
        'punctuation' => ['Gone quiet (hourly)', 'segment-gone-quiet-hourly-2026-10-07.xlsx'],
        'accents' => ['Été café', 'segment-ete-cafe-2026-10-07.xlsx'],
        'only symbols' => ['!!!', 'segment-2026-10-07.xlsx'],
        'a long name' => [str_repeat('ab-', 30), 'segment-'.rtrim(Str::limit(str_repeat('ab-', 30), 60, ''), '-').'-2026-10-07.xlsx'],
    ]);

    it('keeps the file name within what the download route accepts', function () {
        $this->segment->update(['name' => str_repeat('abc def ', 30)]);

        ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Csv->value]);

        $name = ($this->pushedJob)()[0]['name'];

        expect(strlen($name))->toBeLessThanOrEqual(84)
            ->and($name)->toMatch('/^[A-Za-z0-9._-]{1,100}$/');
    });

    it('is hidden while the segment is not published', function () {
        $draft = Segment::factory()->for($this->org)->create(['name' => 'Draft']);

        ($this->page)($draft)->assertActionHidden('exportMembers');
    });

    it('is disabled, with a tooltip, while the members are being computed', function () {
        $this->segment->update(['is_syncing' => true]);

        $page = ($this->page)()->assertActionVisible('exportMembers')->assertActionDisabled('exportMembers');

        expect($page->instance()->getAction('exportMembers')->getTooltip())->toBe('Wait until the members are up to date.');

        $page->call('mountAction', 'exportMembers')->assertActionNotMounted()->call('callMountedAction');

        expect(($this->pushedJob)())->toBe([]);

        $this->segment->update(['is_syncing' => false]);

        $page = ($this->page)()->assertActionEnabled('exportMembers');

        expect($page->instance()->getAction('exportMembers')->getTooltip())->toBeNull();
    });

    it('exports a segment that has pending changes', function () {
        $this->segment->update(['draft_rules' => [['conditions' => []]]]);

        ($this->page)()
            ->assertActionEnabled('exportMembers')
            ->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->ann->id, $this->cid->id]);
    });
});

it('delivers the contact export file of the members', function () {
    Storage::fake('local');
    $admin = ($this->actAs)(OrganizationRole::Admin);

    ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Xlsx->value]);

    $notification = $admin->notifications()->sole();
    $file = Storage::disk('local')->files('exports')[0];
    $rows = SimpleExcelReader::create(Storage::disk('local')->path($file), 'xlsx')->getRows()->all();

    expect($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('2 contacts')
        ->and(array_keys($rows[0]))->toContain('name', 'email', 'all companies')
        ->and(array_column($rows, 'name'))->toBe(['Ann', 'Cid']);
});

it('exports as a CSV for the owner', function () {
    Queue::fake();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    ($this->page)()->callAction('exportMembers', ['format' => ExportFormat::Csv->value]);

    expect(($this->pushedJob)()[0]['format'])->toBe('csv');
});

it('shows the action to the owner and the admins only', function (?OrganizationRole $role, bool $visible) {
    if ($role === null) {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);
    } else {
        ($this->actAs)($role);
    }

    $page = ($this->page)();

    $visible ? $page->assertActionVisible('exportMembers') : $page->assertActionHidden('exportMembers');
})->with([
    'owner' => [null, true],
    'admin' => [OrganizationRole::Admin, true],
    'member' => [OrganizationRole::Member, false],
    'viewer' => [OrganizationRole::Viewer, false],
]);

it('refuses a member or a viewer who calls the action anyway', function (OrganizationRole $role) {
    ($this->actAs)($role);

    ($this->refuses)();
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);
