<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\OrganizationRole;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Exceptions\UsedInSegmentsException;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Resources\CompanyTypes\CompanyTypeResource;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->agency = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Agency']);
    $this->startup = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Startup']);
    $this->pixel = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Pixel', 'company_type_id' => $this->agency->id]);
    $this->nova = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nova', 'company_type_id' => $this->startup->id]);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };
});

it('renders and lists the types with their number of companies', function () {
    $this->get(CompanyTypeResource::getUrl('index'))->assertOk();

    Livewire::test(ManageCompanyTypes::class)
        ->assertCanSeeTableRecords([$this->agency, $this->startup], inOrder: true)
        ->assertTableColumnStateSet('companies_count', 1, $this->agency)
        ->assertTableColumnStateSet('companies_count', 1, $this->startup);
});

it('lists the types of the current organization only', function () {
    $foreign = CompanyType::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Foreign']);

    Livewire::test(ManageCompanyTypes::class)->assertCanNotSeeTableRecords([$foreign]);

    $this->get(CompanyTypeResource::getUrl('index'))->assertOk();
});

it('is in the Audience group right after the company fields', function () {
    expect(CompanyTypeResource::getNavigationLabel())->toBe('Company types')
        ->and(CompanyTypeResource::getNavigationGroup())->toBe('Audience')
        ->and(CompanyTypeResource::getNavigationSort())->toBe(12)
        ->and(CompanyTypeResource::getNavigationSort())->toBeGreaterThan(CompanyCustomFieldResource::getNavigationSort());
});

describe('creating and renaming', function () {
    it('creates a type', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(CreateAction::class, ['name' => 'Partner'])
            ->assertHasNoActionErrors();

        expect(CompanyType::query()->where('name', 'Partner')->sole()->organization_id)->toBe($this->org->id);
    });

    it('refuses a name that exists, ignoring case', function (string $name) {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(CreateAction::class, ['name' => $name])
            ->assertHasActionErrors(['name']);

        expect(CompanyType::where('organization_id', $this->org->id)->count())->toBe(2);
    })->with(['Agency', 'agency', 'AGENCY']);

    it('allows the name of a type of another organization', function () {
        CompanyType::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Partner']);

        Livewire::test(ManageCompanyTypes::class)
            ->callAction(CreateAction::class, ['name' => 'Partner'])
            ->assertHasNoActionErrors();
    });

    it('allows the name of a deleted type', function () {
        $this->startup->delete();

        Livewire::test(ManageCompanyTypes::class)
            ->callAction(CreateAction::class, ['name' => 'Startup'])
            ->assertHasNoActionErrors();
    });

    it('requires a name of 255 characters at most', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(CreateAction::class, ['name' => ''])
            ->assertHasActionErrors(['name' => 'required']);

        Livewire::test(ManageCompanyTypes::class)
            ->callAction(CreateAction::class, ['name' => str_repeat('a', 256)])
            ->assertHasActionErrors(['name' => 'max']);
    });

    it('renames a type, which shows on its companies', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(TestAction::make(EditAction::class)->table($this->agency), ['name' => 'Digital agency'])
            ->assertHasNoActionErrors();

        Livewire::test(ListCompanies::class)
            ->assertTableColumnFormattedStateSet('companyTypeWithTrashed.name', 'Digital agency', $this->pixel);
    });

    it('keeps its own name valid when it is edited', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(TestAction::make(EditAction::class)->table($this->agency), ['name' => 'Agency'])
            ->assertHasNoActionErrors();
    });

    it('refuses to rename to the name of another type', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(TestAction::make(EditAction::class)->table($this->agency), ['name' => 'startup'])
            ->assertHasActionErrors(['name']);
    });
});

describe('deleting', function () {
    it('soft deletes the type and keeps it on its companies', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($this->startup))
            ->assertHasNoActionErrors();

        expect($this->startup->fresh()->trashed())->toBeTrue()
            ->and($this->nova->fresh()->company_type_id)->toBe($this->startup->id);

        Livewire::test(ListCompanies::class)
            ->assertTableColumnFormattedStateSet('companyTypeWithTrashed.name', 'Startup (deleted)', $this->nova)
            ->assertTableColumnFormattedStateSet('companyTypeWithTrashed.name', 'Agency', $this->pixel);
    });

    it('asks for a confirmation that explains what happens to the companies', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->assertTableActionExists('delete', fn (DeleteAction $action): bool => $action->getModalDescription() === 'Companies of this type keep it: it shows as "(deleted)" on them until you restore it or change their type.'
                && $action->isConfirmationRequired(), $this->startup);
    });

    it('deletes several types at once', function () {
        Livewire::test(ManageCompanyTypes::class)
            ->selectTableRecords([$this->agency, $this->startup])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertHasNoActionErrors();

        expect(CompanyType::where('organization_id', $this->org->id)->count())->toBe(0)
            ->and(CompanyType::onlyTrashed()->count())->toBe(2);
    });

    it('is refused while a segment uses the type', function () {
        $segment = Segment::factory()->for($this->org)->create([
            'name' => 'Agencies',
            'rules' => [(new SegmentRuleData('rule-1', 'Rule', [
                SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$this->agency->id]]),
            ]))->toArray()],
            'is_published' => true,
        ]);

        Livewire::test(ManageCompanyTypes::class)
            ->assertTableActionDisabled('delete', $this->agency)
            ->assertTableActionEnabled('delete', $this->startup);

        expect(fn () => $this->agency->delete())->toThrow(UsedInSegmentsException::class, 'Agencies')
            ->and($this->agency->fresh()->trashed())->toBeFalse()
            ->and($segment->exists)->toBeTrue();
    });

    it('keeps the types a segment uses when several are deleted', function () {
        Segment::factory()->for($this->org)->create([
            'name' => 'Agencies',
            'rules' => [(new SegmentRuleData('rule-1', 'Rule', [
                SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$this->agency->id]]),
            ]))->toArray()],
            'is_published' => true,
        ]);

        Livewire::test(ManageCompanyTypes::class)
            ->selectTableRecords([$this->agency, $this->startup])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        $notifications = new Notifications;
        $notifications->mount();

        expect($this->agency->fresh()->trashed())->toBeFalse()
            ->and($this->startup->fresh()->trashed())->toBeTrue()
            ->and($notifications->notifications->last()->getBody())->toContain('1 record was kept because it is used in the conditions of: "Agencies".');
    });
});

describe('the companies of a deleted type', function () {
    beforeEach(function () {
        $this->startup->delete();
    });

    it('does not offer the deleted type in the company form', function () {
        $options = Livewire::test(CreateCompany::class)->instance()->getSchema('form')->getFlatFields()['company_type_id']->getOptions();

        expect($options)->toHaveKey($this->agency->id)
            ->and($options)->not->toHaveKey($this->startup->id);
    });

    it('shows the deleted type as the selected value and keeps it when saved', function () {
        $this->actingAs(($this->actAs)(OrganizationRole::Member));

        $page = Livewire::test(EditCompany::class, ['record' => $this->nova->id])
            ->assertFormSet(['company_type_id' => $this->startup->id]);

        expect($page->instance()->getSchema('form')->getFlatFields()['company_type_id']->getOptionLabel())->toBe('Startup (deleted)');

        $page->call('save')->assertHasNoFormErrors();

        expect($this->nova->fresh()->company_type_id)->toBe($this->startup->id);
    });

    it('refuses a forged deleted type when creating a company', function () {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Forged', 'company_type_id' => $this->startup->id])
            ->call('create')
            ->assertHasFormErrors(['company_type_id']);

        expect(Company::query()->where('name', 'Forged')->exists())->toBeFalse();
    });

    it('refuses a forged deleted type on a company that had another type', function () {
        Livewire::test(EditCompany::class, ['record' => $this->pixel->id])
            ->fillForm(['company_type_id' => $this->startup->id])
            ->call('save')
            ->assertHasFormErrors(['company_type_id']);

        expect($this->pixel->fresh()->company_type_id)->toBe($this->agency->id);
    });

    it('lets the user pick another type', function () {
        Livewire::test(EditCompany::class, ['record' => $this->nova->id])
            ->fillForm(['company_type_id' => $this->agency->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->nova->fresh()->company_type_id)->toBe($this->agency->id);
    });

    it('shows "(deleted)" in the global search details', function () {
        $details = CompanyResource::getGlobalSearchResultDetails(CompanyResource::getGlobalSearchEloquentQuery()->where('name', 'Nova')->sole());

        expect($details['Type'])->toBe('Startup (deleted)');
    });

    it('keeps the type filter on the active types only', function () {
        $options = Livewire::test(ListCompanies::class)->instance()->getTableFiltersForm()->getFlatFields()['company_type_id.value']->getOptions();

        expect($options)->toBe([$this->agency->id => 'Agency']);
    });
});

describe('restoring', function () {
    it('restores a type, and its companies show it again', function () {
        $this->startup->delete();

        Livewire::test(ManageCompanyTypes::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make(RestoreAction::class)->table($this->startup))
            ->assertHasNoActionErrors();

        expect($this->startup->fresh()->trashed())->toBeFalse();

        Livewire::test(ListCompanies::class)
            ->assertTableColumnFormattedStateSet('companyTypeWithTrashed.name', 'Startup', $this->nova);
    });

    it('is refused when an active type has the same name', function () {
        $this->startup->delete();
        CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'startup']);

        Livewire::test(ManageCompanyTypes::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make(RestoreAction::class)->table($this->startup))
            ->assertNotified('A company type named "Startup" already exists. Rename one of them first.');

        expect($this->startup->fresh()->trashed())->toBeTrue();
    });

    it('restores several types and keeps the ones whose name is taken', function () {
        $this->startup->delete();
        $this->agency->delete();
        CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Agency']);

        Livewire::test(ManageCompanyTypes::class)
            ->filterTable('trashed', true)
            ->selectTableRecords([$this->agency, $this->startup])
            ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

        expect($this->startup->fresh()->trashed())->toBeFalse()
            ->and($this->agency->fresh()->trashed())->toBeTrue();
    });
});

describe('who can manage them', function () {
    it('lets admins and the owner manage the types', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ManageCompanyTypes::class)
            ->assertSuccessful()
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionVisible(EditAction::class, $this->agency)
            ->assertTableActionVisible('delete', $this->agency);
    })->with([OrganizationRole::Admin, OrganizationRole::Owner]);

    it('shows members and viewers the list read-only', function (OrganizationRole $role) {
        ($this->actAs)($role);
        $this->startup->delete();

        Livewire::test(ManageCompanyTypes::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->agency])
            ->assertActionHidden(CreateAction::class)
            ->assertTableActionHidden(EditAction::class, $this->agency)
            ->assertTableActionHidden('delete', $this->agency)
            ->filterTable('trashed', true)
            ->assertTableActionHidden('restore', $this->startup);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
