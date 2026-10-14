<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Services\Owners\OwnerOptions;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    Carbon::setTestNow('2026-10-14 12:00:00');

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Martin']);
    $this->org = $this->alice->personalOrganization();
    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Stone']);
    $this->vera = User::factory()->onboardingCompleted()->create(['name' => 'Vera Viewer']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->attach($this->vera, ['role' => OrganizationRole::Viewer->value]);

    $this->eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Eve Other']);

    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->makeContact = fn (string $name, ?User $owner, ?Organization $org = null): Contact => Contact::factory()->create([
        'organization_id' => ($org ?? $this->org)->id,
        'owner_id' => $owner?->id,
        'name' => $name,
    ]);

    $this->formerMember = function (string $name = 'Legacy Lead'): array {
        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
        $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
        $contact = ($this->makeContact)($name, $carl);
        $this->org->members()->detach($carl->id);

        return [$carl, $contact->fresh()];
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('the owner of a contact', function () {
    test('a new contact is owned by its creator by default', function () {
        Livewire::test(CreateContact::class)
            ->assertSchemaStateSet(['owner_id' => $this->alice->id])
            ->fillForm(['name' => 'Nora Lane', 'email' => 'nora@example.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Contact::query()->where('name', 'Nora Lane')->firstOrFail()->owner_id)->toBe($this->alice->id);
    });

    test('a contact can be given to another member or to nobody', function () {
        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Ola Berg', 'email' => 'ola@example.com', 'owner_id' => $this->bob->id])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Pia Moss', 'email' => 'pia@example.com', 'owner_id' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Contact::query()->where('name', 'Ola Berg')->firstOrFail()->owner_id)->toBe($this->bob->id)
            ->and(Contact::query()->where('name', 'Pia Moss')->firstOrFail()->owner_id)->toBeNull();
    });

    test('the owner options are the current members ordered by name', function () {
        $field = Livewire::test(CreateContact::class)->instance()->getSchema('form')->getFlatFields()['owner_id'];

        expect($field->getLabel())->toBe('Contact owner')
            ->and($field->getOptions())->toBe([
                $this->alice->id => 'Alice Martin',
                $this->bob->id => 'Bob Stone',
                $this->vera->id => 'Vera Viewer',
            ]);
    });

    test('a forged owner outside the options is refused', function (string $forged) {
        $value = match ($forged) {
            'other organization' => $this->eve->id,
            'letters' => 'abc',
            'unknown id' => 99999999,
        };

        Livewire::test(CreateContact::class)
            ->fillForm(['name' => 'Forged', 'email' => 'forged@example.com', 'owner_id' => $value])
            ->call('create')
            ->assertHasFormErrors(['owner_id' => 'in']);

        expect(Contact::query()->where('name', 'Forged')->exists())->toBeFalse();
    })->with(['other organization', 'letters', 'unknown id']);

    test('a removed member stays the owner and is marked everywhere', function () {
        [$carl, $legacy] = ($this->formerMember)();

        expect($legacy->owner_id)->toBe($carl->id)
            ->and($legacy->ownerLabel())->toBe('Carl Weber (no longer a member)');

        Livewire::test(ListContacts::class)
            ->assertTableColumnStateSet('owner_id', 'Carl Weber (no longer a member)', $legacy);

        Livewire::test(ContactDetailsWidget::class, ['record' => $legacy])
            ->assertSee('Carl Weber (no longer a member)');

        expect(OwnerOptions::forForm($legacy))->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
            ->and(OwnerOptions::forForm(null))->not->toHaveKey($carl->id);

        $field = Livewire::test(EditContact::class, ['record' => $legacy->id])->instance()->getSchema('form')->getFlatFields()['owner_id'];

        expect($field->getOptions())->toHaveKey($carl->id, 'Carl Weber (no longer a member)');

        Livewire::test(EditContact::class, ['record' => $legacy->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($legacy->fresh()->owner_id)->toBe($carl->id);
    });

    test('a former member cannot be given to another contact', function () {
        [$carl] = ($this->formerMember)();
        $lead = ($this->makeContact)('Fresh Lead', $this->alice);

        Livewire::test(EditContact::class, ['record' => $lead->id])
            ->fillForm(['owner_id' => $carl->id])
            ->call('save')
            ->assertHasFormErrors(['owner_id' => 'in']);

        expect($lead->fresh()->owner_id)->toBe($this->alice->id);
    });

    test('a member can change the owner on the edit page', function () {
        $lead = ($this->makeContact)('Bob Lead', $this->alice);
        $this->actingAs($this->bob);
        Filament::setTenant($this->org);

        Livewire::test(EditContact::class, ['record' => $lead->id])
            ->fillForm(['owner_id' => $this->bob->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($lead->fresh()->owner_id)->toBe($this->bob->id);
    });

    test('the contact page shows no owner for an unowned contact', function () {
        Livewire::test(ContactDetailsWidget::class, ['record' => ($this->makeContact)('Nobody', null)])
            ->assertSee('Contact owner')
            ->assertSee('No owner');
    });

    test('the my contacts tab shows only the contacts I own', function () {
        $mine = ($this->makeContact)('Mine', $this->alice);
        $his = ($this->makeContact)('Bobs', $this->bob);
        $none = ($this->makeContact)('Nobody', null);

        Livewire::test(ListContacts::class)
            ->set('activeTab', 'mine')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$his, $none])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine, $his, $none]);
    });

    test('the owner filter narrows the table and lists former owners only when they own a contact', function () {
        $mine = ($this->makeContact)('Mine', $this->alice);
        $his = ($this->makeContact)('Bobs', $this->bob);

        Livewire::test(ListContacts::class)
            ->filterTable('owner_id', $this->bob->id)
            ->assertCanSeeTableRecords([$his])
            ->assertCanNotSeeTableRecords([$mine]);

        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);

        expect(OwnerOptions::forFilter(Contact::class))->not->toContain('Carl Weber (no longer a member)');

        ($this->makeContact)('Legacy', $carl);

        expect(OwnerOptions::forFilter(Contact::class))->toHaveKey($carl->id, 'Carl Weber (no longer a member)');

        $options = Livewire::test(ListContacts::class)->instance()->getTable()->getFilter('owner_id')->getOptions();

        expect($options)->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
            ->and($options)->toHaveKey($this->bob->id, 'Bob Stone');
    });

    test('another organization never leaks into the table, the tabs or the options', function () {
        $spy = ($this->makeContact)('Spy', $this->eve, $this->eve->personalOrganization());

        expect(OwnerOptions::forForm(null))->not->toHaveKey($this->eve->id)
            ->and(OwnerOptions::forFilter(Contact::class))->not->toHaveKey($this->eve->id);

        Livewire::test(ListContacts::class)
            ->assertCanNotSeeTableRecords([$spy])
            ->set('activeTab', 'mine')
            ->assertCanNotSeeTableRecords([$spy]);

        Livewire::test(ListContacts::class)
            ->filterTable('owner_id', $this->eve->id)
            ->assertCanNotSeeTableRecords([$spy]);
    });

    test('deleting an owner account keeps the contact without an owner', function () {
        $his = ($this->makeContact)('Bobs', $this->bob);

        $this->bob->delete();

        expect(Contact::query()->find($his->id))->not->toBeNull()
            ->and($his->fresh()->owner_id)->toBeNull();
    });

    test('a soft-deleted contact keeps its owner when restored', function () {
        $his = ($this->makeContact)('Bobs', $this->bob);

        $his->delete();
        $his->restore();

        expect($his->fresh()->owner_id)->toBe($this->bob->id);
    });

    test('the contacts table does not query the owner of every row', function () {
        $countUserQueries = function (): int {
            $queries = 0;
            DB::listen(function ($query) use (&$queries): void {
                if (str_contains($query->sql, 'from "users"')) {
                    $queries++;
                }
            });

            Livewire::test(ListContacts::class);

            return $queries;
        };

        ($this->makeContact)('Contact 0', $this->bob);
        $withOne = $countUserQueries();

        foreach (range(1, 4) as $i) {
            ($this->makeContact)("Contact {$i}", $this->vera);
        }

        expect($countUserQueries())->toBe($withOne);
    });

    test('the contacts table reads owner memberships once whatever the number of owners', function () {
        $countMembershipQueries = function (): int {
            $queries = 0;
            DB::listen(function ($query) use (&$queries): void {
                if (str_contains($query->sql, 'organization_user')) {
                    $queries++;
                }
            });

            Livewire::test(ListContacts::class);

            return $queries;
        };

        ($this->makeContact)('Contact 0', $this->bob);
        Livewire::test(ListContacts::class);
        $withOne = $countMembershipQueries();

        foreach (range(1, 4) as $i) {
            $owner = User::factory()->onboardingCompleted()->create(['name' => "Owner {$i}"]);
            $this->org->members()->attach($owner, ['role' => OrganizationRole::Member->value]);
            ($this->makeContact)("Contact {$i}a", $owner);
            ($this->makeContact)("Contact {$i}b", $owner);
        }

        expect($countMembershipQueries())->toBe($withOne);
    });

    test('an owner name is escaped in the list and on the contact page', function (string $payload) {
        $this->bob->update(['name' => $payload]);
        $his = ($this->makeContact)('Bobs', $this->bob);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ListContacts::class)->assertSuccessful()->html(), $payload);
        SecurityPayloads::assertHtmlIsInert(Livewire::test(ContactDetailsWidget::class, ['record' => $his])->html(), $payload);
    })->with(SecurityPayloads::xss());
});

describe('the bulk assign owner action on contacts', function () {
    beforeEach(function () {
        $this->c1 = ($this->makeContact)('C1', $this->alice);
        $this->c2 = ($this->makeContact)('C2', null);
        $this->c3 = ($this->makeContact)('C3', $this->bob);
    });

    test('contacts are assigned to a member in one step', function () {
        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$this->c1, $this->c2], ['owner_id' => $this->bob->id])
            ->assertNotified('Assigned 2 contacts to Bob Stone');

        expect($this->c1->fresh()->owner_id)->toBe($this->bob->id)
            ->and($this->c2->fresh()->owner_id)->toBe($this->bob->id)
            ->and($this->c3->fresh()->owner_id)->toBe($this->bob->id);
    });

    test('one contact is worded in the singular', function () {
        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$this->c2], ['owner_id' => $this->alice->id])
            ->assertNotified('Assigned 1 contact to Alice Martin');
    });

    test('leaving the owner empty clears it', function () {
        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$this->c1, $this->c3], ['owner_id' => null])
            ->assertNotified('Removed the owner of 2 contacts');

        expect($this->c1->fresh()->owner_id)->toBeNull()
            ->and($this->c3->fresh()->owner_id)->toBeNull();
    });

    test('a forged owner outside the members is refused', function (string $forged) {
        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
        $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
        ($this->makeContact)('Legacy', $carl);
        $this->org->members()->detach($carl->id);

        $value = match ($forged) {
            'other organization' => $this->eve->id,
            'letters' => 'abc',
            'unknown id' => 99999999,
            'former member' => $carl->id,
        };

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$this->c1], ['owner_id' => $value])
            ->assertHasTableBulkActionErrors(['owner_id' => 'in']);

        expect($this->c1->fresh()->owner_id)->toBe($this->alice->id);
    })->with(['other organization', 'letters', 'unknown id', 'former member']);

    test('who sees the bulk action follows the edit rule', function (string $user, bool $visible) {
        $this->actingAs($this->{$user});
        Filament::setTenant($this->org);

        $page = Livewire::test(ListContacts::class);

        $visible ? $page->assertTableBulkActionVisible('assignOwner') : $page->assertTableBulkActionHidden('assignOwner');
    })->with([
        'owner' => ['alice', true],
        'member' => ['bob', true],
        'viewer' => ['vera', false],
    ]);

    test('a viewer cannot run the bulk action through a forged call', function () {
        $this->actingAs($this->vera);
        Filament::setTenant($this->org);

        Livewire::test(ListContacts::class)
            ->call('mountTableBulkAction', 'assignOwner', [(string) $this->c1->id])
            ->assertActionNotMounted()
            ->call('callMountedTableBulkAction', ['owner_id' => $this->vera->id]);

        expect($this->c1->fresh()->owner_id)->toBe($this->alice->id);
    });

    test('contacts of another organization are never touched', function () {
        $spy = ($this->makeContact)('Spy', $this->eve, $this->eve->personalOrganization());

        Livewire::test(ListContacts::class)
            ->assertCanNotSeeTableRecords([$spy])
            ->callTableBulkAction('assignOwner', Contact::query()->get(), ['owner_id' => $this->bob->id]);

        expect($spy->fresh()->owner_id)->toBe($this->eve->id);

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$spy], ['owner_id' => $this->bob->id]);

        expect($spy->fresh()->owner_id)->toBe($this->eve->id);
    });

    test('a member name with markup is plain text in the notification', function () {
        $this->bob->update(['name' => '<b>Bob</b>']);

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$this->c1], ['owner_id' => $this->bob->id])
            ->assertNotified('Assigned 1 contact to &lt;b&gt;Bob&lt;/b&gt;');
    });

    test('the update goes through the model so the segments of the contact are resynced', function () {
        Segment::factory()->for($this->org)->published()->create();

        Queue::fake();

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('assignOwner', [$this->c1], ['owner_id' => $this->bob->id]);

        Queue::assertPushed(ResyncContactSegments::class);
    });
});
