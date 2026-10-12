<?php

use App\Enums\ContactStatus;
use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ExportContactsJob;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

    $this->vip = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann', 'email' => 'ann@x.test', 'status' => ContactStatus::Lead, 'created_at' => now()->subDays(3)]);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob', 'email' => 'bob@x.test', 'status' => ContactStatus::Lead, 'created_at' => now()->subDays(2)]);
    $this->cid = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Cid', 'email' => 'cid@x.test', 'status' => ContactStatus::Partner, 'created_at' => now()->subDay()]);
    $this->ann->tags()->sync([$this->vip->id]);
    $this->bob->tags()->sync([$this->vip->id]);

    $this->pushedJob = function (): array {
        $jobs = Queue::pushed(ExportContactsJob::class);

        return $jobs->map(fn (ExportContactsJob $job): array => (fn (): array => [
            'ids' => $this->contactIds,
            'organization' => $this->organizationId,
            'user' => $this->userId,
            'format' => $this->format,
            'name' => $this->downloadName,
        ])->call($job))->all();
    };
});

describe('an admin', function () {
    beforeEach(function () {
        $this->admin = ($this->actAs)(OrganizationRole::Admin);
    });

    it('exports what the list shows, in the order of the list', function () {
        Livewire::test(ListContacts::class)
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->cid->id, $this->bob->id, $this->ann->id])
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['format'])->toBe('xlsx');
    });

    it('applies the filters of the list', function () {
        Livewire::test(ListContacts::class)
            ->filterTable('tags', [$this->vip->id])
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bob->id, $this->ann->id]);
    });

    it('applies the search and the sort of the list', function () {
        Livewire::test(ListContacts::class)
            ->searchTable('x.test')
            ->sortTable('name')
            ->callAction('exportContacts', ['format' => ExportFormat::Csv->value]);

        $jobs = ($this->pushedJob)();

        expect($jobs[0]['ids'])->toBe([$this->ann->id, $this->bob->id, $this->cid->id])
            ->and($jobs[0]['format'])->toBe('csv');

        Queue::fake();

        Livewire::test(ListContacts::class)
            ->searchTable('Cid')
            ->callAction('exportContacts', ['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->cid->id]);
    });

    it('applies the status filter and the trashed filter', function () {
        $dan = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Dan', 'status' => ContactStatus::Lead]);
        $dan->delete();

        Livewire::test(ListContacts::class)
            ->filterTable('trashed', 0)
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$dan->id]);

        Queue::fake();

        Livewire::test(ListContacts::class)
            ->filterTable('status', [ContactStatus::Partner->value])
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->cid->id]);
    });

    it('exports all pages, not only the one shown', function () {
        Contact::factory()->count(30)->create(['organization_id' => $this->org->id, 'created_at' => now()->subYear()]);

        Livewire::test(ListContacts::class)
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(33);
    });

    it('exports only the contacts of the organization in use', function () {
        Contact::factory()->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id]);

        Livewire::test(ListContacts::class)->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(3);
    });

    it('names the file with the local date of the organization', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        Livewire::test(ListContacts::class)->callAction('exportContacts', ['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['name'])->toBe('contacts-2026-10-07.csv');
    });

    it('defaults to Excel and refuses another format', function () {
        Livewire::test(ListContacts::class)
            ->mountAction('exportContacts')
            ->assertSchemaStateSet(['format' => ExportFormat::Xlsx])
            ->setActionData(['format' => 'pdf'])
            ->callMountedAction()
            ->assertHasActionErrors(['format']);

        Queue::assertNotPushed(ExportContactsJob::class);
    });

    it('warns when there is nothing to export', function () {
        Livewire::test(ListContacts::class)
            ->searchTable('nobody matches this')
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Nothing to export.');

        Queue::assertNotPushed(ExportContactsJob::class);
    });

    it('exports the selected contacts as a csv', function () {
        Livewire::test(ListContacts::class)
            ->callTableBulkAction('exportSelected', [$this->ann, $this->cid], ['format' => ExportFormat::Csv->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toEqualCanonicalizing([$this->ann->id, $this->cid->id])
            ->and($jobs[0]['format'])->toBe('csv')
            ->and($jobs[0]['user'])->toBe($this->admin->id);
    });
});

describe('the owner', function () {
    it('exports too', function () {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);

        Livewire::test(ListContacts::class)
            ->assertActionVisible('exportContacts')
            ->assertTableBulkActionVisible('exportSelected')
            ->callAction('exportContacts', ['format' => ExportFormat::Xlsx->value]);

        Queue::assertPushed(ExportContactsJob::class, 1);
    });
});

describe('a member or a viewer', function () {
    it('sees neither export action', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListContacts::class)
            ->assertActionHidden('exportContacts')
            ->assertTableBulkActionHidden('exportSelected');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('is refused when calling the actions anyway', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListContacts::class)
            ->call('mountAction', 'exportContacts', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Livewire::test(ListContacts::class)
            ->set('selectedTableRecords', [$this->ann->getKey()])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Queue::assertNotPushed(ExportContactsJob::class);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
