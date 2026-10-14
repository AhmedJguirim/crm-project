<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\Organization;
use App\Models\User;
use App\Services\Owners\OwnerOptions;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    $this->makeCompany = fn (string $name, ?User $owner, ?Organization $org = null): Company => Company::factory()->create([
        'organization_id' => ($org ?? $this->org)->id,
        'owner_id' => $owner?->id,
        'name' => $name,
    ]);

    $this->formerMember = function (string $name = 'Legacy Corp'): array {
        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
        $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
        $company = ($this->makeCompany)($name, $carl);
        $this->org->members()->detach($carl->id);

        return [$carl, $company->fresh()];
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('the owner of a company', function () {
    test('a new company is owned by its creator by default', function () {
        Livewire::test(CreateCompany::class)
            ->assertSchemaStateSet(['owner_id' => $this->alice->id])
            ->fillForm(['name' => 'Nordwind'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Company::query()->where('name', 'Nordwind')->firstOrFail()->owner_id)->toBe($this->alice->id);
    });

    test('a company can be given to another member or to nobody', function () {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Ola Corp', 'owner_id' => $this->bob->id])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Pia Corp', 'owner_id' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Company::query()->where('name', 'Ola Corp')->firstOrFail()->owner_id)->toBe($this->bob->id)
            ->and(Company::query()->where('name', 'Pia Corp')->firstOrFail()->owner_id)->toBeNull();
    });

    test('the owner options are the current members ordered by name', function () {
        $field = Livewire::test(CreateCompany::class)->instance()->getSchema('form')->getFlatFields()['owner_id'];

        expect($field->getLabel())->toBe('Company owner')
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

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Forged', 'owner_id' => $value])
            ->call('create')
            ->assertHasFormErrors(['owner_id' => 'in']);

        expect(Company::query()->where('name', 'Forged')->exists())->toBeFalse();
    })->with(['other organization', 'letters', 'unknown id']);

    test('a removed member stays the owner and is marked everywhere', function () {
        [$carl, $legacy] = ($this->formerMember)();

        expect($legacy->owner_id)->toBe($carl->id)
            ->and($legacy->ownerLabel())->toBe('Carl Weber (no longer a member)');

        Livewire::test(ListCompanies::class)
            ->assertTableColumnStateSet('owner_id', 'Carl Weber (no longer a member)', $legacy);

        expect(OwnerOptions::forForm($legacy))->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
            ->and(OwnerOptions::forForm(null))->not->toHaveKey($carl->id);

        $field = Livewire::test(EditCompany::class, ['record' => $legacy->id])->instance()->getSchema('form')->getFlatFields()['owner_id'];

        expect($field->getOptions())->toHaveKey($carl->id, 'Carl Weber (no longer a member)');

        Livewire::test(EditCompany::class, ['record' => $legacy->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($legacy->fresh()->owner_id)->toBe($carl->id);
    });

    test('a former member cannot be given to another company', function () {
        [$carl] = ($this->formerMember)();
        $company = ($this->makeCompany)('Fresh Corp', $this->alice);

        Livewire::test(EditCompany::class, ['record' => $company->id])
            ->fillForm(['owner_id' => $carl->id])
            ->call('save')
            ->assertHasFormErrors(['owner_id' => 'in']);

        expect($company->fresh()->owner_id)->toBe($this->alice->id);
    });

    test('a member can change the owner on the edit page', function () {
        $company = ($this->makeCompany)('Bob Corp', $this->alice);
        $this->actingAs($this->bob);
        Filament::setTenant($this->org);

        Livewire::test(EditCompany::class, ['record' => $company->id])
            ->fillForm(['owner_id' => $this->bob->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($company->fresh()->owner_id)->toBe($this->bob->id);
    });

    test('the my companies tab shows only the companies I own', function () {
        $mine = ($this->makeCompany)('Mine', $this->alice);
        $his = ($this->makeCompany)('Bobs', $this->bob);
        $none = ($this->makeCompany)('Nobody', null);

        Livewire::test(ListCompanies::class)
            ->set('activeTab', 'mine')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$his, $none])
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$mine, $his, $none]);
    });

    test('the owner filter narrows the table and lists former owners only when they own a company', function () {
        $mine = ($this->makeCompany)('Mine', $this->alice);
        $his = ($this->makeCompany)('Bobs', $this->bob);

        Livewire::test(ListCompanies::class)
            ->filterTable('owner_id', $this->bob->id)
            ->assertCanSeeTableRecords([$his])
            ->assertCanNotSeeTableRecords([$mine]);

        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);

        expect(OwnerOptions::forFilter(Company::class))->not->toContain('Carl Weber (no longer a member)');

        ($this->makeCompany)('Legacy', $carl);

        expect(OwnerOptions::forFilter(Company::class))->toHaveKey($carl->id, 'Carl Weber (no longer a member)');

        $options = Livewire::test(ListCompanies::class)->instance()->getTable()->getFilter('owner_id')->getOptions();

        expect($options)->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
            ->and($options)->toHaveKey($this->bob->id, 'Bob Stone');
    });

    test('another organization never leaks into the table, the tabs or the options', function () {
        $spy = ($this->makeCompany)('Spy', $this->eve, $this->eve->personalOrganization());

        expect(OwnerOptions::forForm(null))->not->toHaveKey($this->eve->id)
            ->and(OwnerOptions::forFilter(Company::class))->not->toHaveKey($this->eve->id);

        Livewire::test(ListCompanies::class)
            ->assertCanNotSeeTableRecords([$spy])
            ->set('activeTab', 'mine')
            ->assertCanNotSeeTableRecords([$spy]);

        Livewire::test(ListCompanies::class)
            ->filterTable('owner_id', $this->eve->id)
            ->assertCanNotSeeTableRecords([$spy]);
    });

    test('deleting an owner account keeps the company without an owner', function () {
        $his = ($this->makeCompany)('Bobs', $this->bob);

        $this->bob->delete();

        expect(Company::query()->find($his->id))->not->toBeNull()
            ->and($his->fresh()->owner_id)->toBeNull();
    });

    test('a soft-deleted company keeps its owner when restored', function () {
        $his = ($this->makeCompany)('Bobs', $this->bob);

        $his->delete();
        $his->restore();

        expect($his->fresh()->owner_id)->toBe($this->bob->id);
    });

    test('the companies table does not query the owner of every row', function () {
        $countUserQueries = function (): int {
            $queries = 0;
            DB::listen(function ($query) use (&$queries): void {
                if (str_contains($query->sql, 'from "users"')) {
                    $queries++;
                }
            });

            Livewire::test(ListCompanies::class);

            return $queries;
        };

        ($this->makeCompany)('Company 0', $this->bob);
        $withOne = $countUserQueries();

        foreach (range(1, 4) as $i) {
            ($this->makeCompany)("Company {$i}", $this->vera);
        }

        expect($countUserQueries())->toBe($withOne);
    });

    test('the companies table reads owner memberships once whatever the number of owners', function () {
        $countMembershipQueries = function (): int {
            $queries = 0;
            DB::listen(function ($query) use (&$queries): void {
                if (str_contains($query->sql, 'organization_user')) {
                    $queries++;
                }
            });

            Livewire::test(ListCompanies::class);

            return $queries;
        };

        ($this->makeCompany)('Company 0', $this->bob);
        Livewire::test(ListCompanies::class);
        $withOne = $countMembershipQueries();

        foreach (range(1, 4) as $i) {
            $owner = User::factory()->onboardingCompleted()->create(['name' => "Owner {$i}"]);
            $this->org->members()->attach($owner, ['role' => OrganizationRole::Member->value]);
            ($this->makeCompany)("Company {$i}a", $owner);
            ($this->makeCompany)("Company {$i}b", $owner);
        }

        expect($countMembershipQueries())->toBe($withOne);
    });

    test('an owner name is escaped in the list', function (string $payload) {
        $this->bob->update(['name' => $payload]);
        $his = ($this->makeCompany)('Bobs', $this->bob);

        SecurityPayloads::assertHtmlIsInert(Livewire::test(ListCompanies::class)->assertSuccessful()->html(), $payload);
    })->with(SecurityPayloads::xss());
});

describe('the bulk assign owner action on companies', function () {
    beforeEach(function () {
        $this->c1 = ($this->makeCompany)('C1', $this->alice);
        $this->c2 = ($this->makeCompany)('C2', null);
        $this->c3 = ($this->makeCompany)('C3', $this->bob);
    });

    test('companies are assigned to a member in one step', function () {
        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$this->c1, $this->c2], ['owner_id' => $this->bob->id])
            ->assertNotified('Assigned 2 companies to Bob Stone');

        expect($this->c1->fresh()->owner_id)->toBe($this->bob->id)
            ->and($this->c2->fresh()->owner_id)->toBe($this->bob->id)
            ->and($this->c3->fresh()->owner_id)->toBe($this->bob->id);
    });

    test('one company is worded in the singular', function () {
        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$this->c2], ['owner_id' => $this->alice->id])
            ->assertNotified('Assigned 1 company to Alice Martin');
    });

    test('leaving the owner empty clears it', function () {
        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$this->c1, $this->c3], ['owner_id' => null])
            ->assertNotified('Removed the owner of 2 companies');

        expect($this->c1->fresh()->owner_id)->toBeNull()
            ->and($this->c3->fresh()->owner_id)->toBeNull();
    });

    test('a forged owner outside the members is refused', function (string $forged) {
        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
        $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
        ($this->makeCompany)('Legacy', $carl);
        $this->org->members()->detach($carl->id);

        $value = match ($forged) {
            'other organization' => $this->eve->id,
            'letters' => 'abc',
            'unknown id' => 99999999,
            'former member' => $carl->id,
        };

        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$this->c1], ['owner_id' => $value])
            ->assertHasTableBulkActionErrors(['owner_id' => 'in']);

        expect($this->c1->fresh()->owner_id)->toBe($this->alice->id);
    })->with(['other organization', 'letters', 'unknown id', 'former member']);

    test('who sees the bulk action follows the edit rule', function (string $user, bool $visible) {
        $this->actingAs($this->{$user});
        Filament::setTenant($this->org);

        $page = Livewire::test(ListCompanies::class);

        $visible ? $page->assertTableBulkActionVisible('assignOwner') : $page->assertTableBulkActionHidden('assignOwner');
    })->with([
        'owner' => ['alice', true],
        'member' => ['bob', true],
        'viewer' => ['vera', false],
    ]);

    test('a viewer cannot run the bulk action through a forged call', function () {
        $this->actingAs($this->vera);
        Filament::setTenant($this->org);

        Livewire::test(ListCompanies::class)
            ->call('mountTableBulkAction', 'assignOwner', [(string) $this->c1->id])
            ->assertActionNotMounted()
            ->call('callMountedTableBulkAction', ['owner_id' => $this->vera->id]);

        expect($this->c1->fresh()->owner_id)->toBe($this->alice->id);
    });

    test('companies of another organization are never touched', function () {
        $spy = ($this->makeCompany)('Spy', $this->eve, $this->eve->personalOrganization());

        Livewire::test(ListCompanies::class)
            ->assertCanNotSeeTableRecords([$spy])
            ->callTableBulkAction('assignOwner', Company::query()->get(), ['owner_id' => $this->bob->id]);

        expect($spy->fresh()->owner_id)->toBe($this->eve->id);

        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$spy], ['owner_id' => $this->bob->id]);

        expect($spy->fresh()->owner_id)->toBe($this->eve->id);
    });

    test('a member name with markup is plain text in the notification', function () {
        $this->bob->update(['name' => '<b>Bob</b>']);

        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$this->c1], ['owner_id' => $this->bob->id])
            ->assertNotified('Assigned 1 company to &lt;b&gt;Bob&lt;/b&gt;');
    });

    test('the update goes through the model so its events fire', function () {
        $updated = 0;
        Company::updated(function () use (&$updated): void {
            $updated++;
        });

        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('assignOwner', [$this->c1, $this->c2], ['owner_id' => $this->bob->id]);

        expect($updated)->toBe(2);
    });
});
