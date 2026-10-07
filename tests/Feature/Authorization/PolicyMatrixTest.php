<?php

use App\Enums\OrganizationRole;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\CompanyCustomFieldPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CompanyTypePolicy;
use App\Policies\ContactPolicy;
use App\Policies\CustomFieldPolicy;
use App\Policies\DealPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\SegmentPolicy;
use App\Policies\TagPolicy;
use App\Policies\TaskPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The model, whether its definition needs an admin for create and update, and its policy.
 *
 * @return array<string, array{0: class-string, 1: bool, 2: class-string}>
 */
function authorizationModels(): array
{
    return [
        'contact' => [Contact::class, false, ContactPolicy::class],
        'company' => [Company::class, false, CompanyPolicy::class],
        'company type' => [CompanyType::class, true, CompanyTypePolicy::class],
        'custom field' => [CustomField::class, true, CustomFieldPolicy::class],
        'company custom field' => [CompanyCustomField::class, true, CompanyCustomFieldPolicy::class],
        'tag' => [Tag::class, false, TagPolicy::class],
        'segment' => [Segment::class, false, SegmentPolicy::class],
        'deal' => [Deal::class, false, DealPolicy::class],
        'task' => [Task::class, false, TaskPolicy::class],
        'invoice' => [Invoice::class, false, InvoicePolicy::class],
        'activity' => [Activity::class, false, ActivityPolicy::class],
    ];
}

function authorizationRecord(string $model, Organization $organization): Model
{
    return $model::factory()->create(['organization_id' => $organization->id]);
}

/** @return Generator<string, array{0: string, 1: string, 2: OrganizationRole, 3: bool}> */
function authorizationMatrix(): Generator
{
    foreach (authorizationModels() as $name => [$model, $definition]) {
        foreach (OrganizationRole::cases() as $role) {
            $edit = $role->canEdit();
            $manage = $role->canManage();

            $expected = [
                'viewAny' => true,
                'view' => true,
                'create' => $definition ? $manage : $edit,
                'update' => $definition ? $manage : $edit,
                'updateAny' => $definition ? $manage : $edit,
                'replicate' => $definition ? $manage : $edit,
                'reorder' => $definition ? $manage : $edit,
                'delete' => $manage,
                'deleteAny' => $manage,
                'restore' => $manage,
                'restoreAny' => $manage,
                'forceDelete' => false,
                'forceDeleteAny' => false,
            ];

            foreach ($expected as $ability => $allowed) {
                yield "{$role->value} {$ability} {$name}" => [$model, $ability, $role, $allowed];
            }
        }
    }
}

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->acme = $this->owner->personalOrganization();
    app(TenantContext::class)->set($this->acme->id);

    $this->userWith = function (OrganizationRole $role, ?Organization $organization = null): User {
        $user = User::factory()->create();
        ($organization ?? $this->acme)->members()->attach($user, ['role' => $role->value]);

        return $user;
    };
});

describe('role capabilities', function () {
    it('are the ones of the matrix', function (OrganizationRole $role, bool $view, bool $edit, bool $manage) {
        expect($role->canView())->toBe($view)
            ->and($role->canEdit())->toBe($edit)
            ->and($role->canManage())->toBe($manage);
    })->with([
        'viewer' => [OrganizationRole::Viewer, true, false, false],
        'member' => [OrganizationRole::Member, true, true, false],
        'admin' => [OrganizationRole::Admin, true, true, true],
        'owner' => [OrganizationRole::Owner, true, true, true],
    ]);

    it('are read from the role of the user in the organization', function () {
        $member = ($this->userWith)(OrganizationRole::Member);

        expect($member->roleIn($this->acme->id))->toBe(OrganizationRole::Member)
            ->and($member->roleIn(Organization::factory()->create()->id))->toBeNull();
    });

    it('are looked up once for all the checks of a user', function () {
        $viewer = ($this->userWith)(OrganizationRole::Viewer);
        $contacts = Contact::factory()->count(20)->create(['organization_id' => $this->acme->id]);
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries += str_contains($query->sql, 'organization_user') ? 1 : 0;
        });

        $contacts->each(fn (Contact $contact) => Gate::forUser($viewer)->allows('update', $contact));

        expect($queries)->toBe(1);
    });

    it('can be forgotten after a role changed', function () {
        $member = ($this->userWith)(OrganizationRole::Member);
        expect(Gate::forUser($member)->allows('delete', Contact::factory()->create(['organization_id' => $this->acme->id])))->toBeFalse();

        $this->acme->members()->updateExistingPivot($member->id, ['role' => OrganizationRole::Admin->value]);
        $member->forgetRoles();

        expect($member->roleIn($this->acme->id))->toBe(OrganizationRole::Admin);
    });
});

describe('policies', function () {
    it('are found for every tenant model', function (string $model, bool $definition, string $policy) {
        expect(Gate::getPolicyFor($model))->toBeInstanceOf($policy);
    })->with(array_map(fn (array $row): array => $row, authorizationModels()));

    it('give every role the abilities of the matrix', function (string $model, string $ability, OrganizationRole $role, bool $allowed) {
        $user = ($this->userWith)($role);
        $argument = in_array($ability, ['viewAny', 'create', 'updateAny', 'deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'], true)
            ? $model
            : authorizationRecord($model, $this->acme);

        expect(Gate::forUser($user)->allows($ability, $argument))->toBe($allowed);
    })->with(authorizationMatrix());

    it('give the extra abilities to admins and the owner only', function (OrganizationRole $role, bool $allowed) {
        $user = ($this->userWith)($role);

        expect(Gate::forUser($user)->allows('import', Contact::class))->toBe($allowed)
            ->and(Gate::forUser($user)->allows('import', Company::class))->toBe($allowed)
            ->and(Gate::forUser($user)->allows('publish', authorizationRecord(Segment::class, $this->acme)))->toBe($allowed)
            ->and(Gate::forUser($user)->allows('cancel', authorizationRecord(Invoice::class, $this->acme)))->toBe($allowed);
    })->with([
        'viewer' => [OrganizationRole::Viewer, false],
        'member' => [OrganizationRole::Member, false],
        'admin' => [OrganizationRole::Admin, true],
        'owner' => [OrganizationRole::Owner, true],
    ]);

    it('deny everything to a member of another organization', function (string $model, bool $definition, string $policy) {
        $globex = Organization::factory()->create();
        $stranger = ($this->userWith)(OrganizationRole::Owner, $globex);
        $record = authorizationRecord($model, $this->acme);

        foreach (['view', 'update', 'replicate', 'delete', 'restore', 'forceDelete'] as $ability) {
            expect(Gate::forUser($stranger)->allows($ability, $record))->toBeFalse("{$ability} {$model}");
        }

        foreach (['viewAny', 'create', 'updateAny', 'deleteAny', 'restoreAny', 'reorder'] as $ability) {
            expect(Gate::forUser($stranger)->allows($ability, $model))->toBeFalse("{$ability} {$model}");
        }
    })->with(authorizationModels());

    it('deny everything to a user who belongs to no organization', function () {
        $nobody = User::factory()->create();

        expect(Gate::forUser($nobody)->allows('viewAny', Contact::class))->toBeFalse()
            ->and(Gate::forUser($nobody)->allows('view', authorizationRecord(Contact::class, $this->acme)))->toBeFalse();
    });

    it('use the organization being worked in for abilities without a record', function () {
        $globex = Organization::factory()->create();
        $adminOfBoth = ($this->userWith)(OrganizationRole::Admin);
        $globex->members()->attach($adminOfBoth, ['role' => OrganizationRole::Viewer->value]);

        expect(Gate::forUser($adminOfBoth)->allows('create', Contact::class))->toBeTrue();

        app(TenantContext::class)->set($globex->id);

        expect(Gate::forUser($adminOfBoth)->allows('create', Contact::class))->toBeFalse();
    });

    it('deny the abilities without a record when no organization is being worked in', function () {
        $admin = ($this->userWith)(OrganizationRole::Admin);
        app(TenantContext::class)->set(null);

        expect(Gate::forUser($admin)->allows('viewAny', Contact::class))->toBeFalse();
    });
});
