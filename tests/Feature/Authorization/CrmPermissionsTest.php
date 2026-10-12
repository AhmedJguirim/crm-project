<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\TaskStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Livewire\Filament\QuickTaskFloatingButton;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->refused = fn ($page, string $action, array $arguments = [], array $context = []) => $page
        ->call('mountAction', $action, $arguments, $context)
        ->assertActionNotMounted()
        ->call('callMountedAction');
});

describe('tags', function () {
    beforeEach(function () {
        $this->tag = Tag::factory()->create(['organization_id' => $this->org->id]);
    });

    it('can only be deleted by an admin, but created and edited by a member', function (OrganizationRole $role, bool $create, bool $delete) {
        ($this->actAs)($role);

        $page = Livewire::test(ListTags::class)->assertSuccessful();
        $create ? $page->assertActionVisible(CreateAction::class) : $page->assertActionHidden(CreateAction::class);
        $create ? $page->assertTableActionVisible(EditAction::class, $this->tag) : $page->assertTableActionHidden(EditAction::class, $this->tag);
        $delete ? $page->assertTableActionVisible('delete', $this->tag) : $page->assertTableActionHidden('delete', $this->tag);
        $delete ? $page->assertTableBulkActionVisible('delete') : $page->assertTableBulkActionHidden('delete');
    })->with([
        'viewer' => [OrganizationRole::Viewer, false, false],
        'member' => [OrganizationRole::Member, true, false],
        'admin' => [OrganizationRole::Admin, true, true],
    ]);
});

describe('companies', function () {
    beforeEach(function () {
        $this->type = CompanyType::factory()->create(['organization_id' => $this->org->id]);
        $this->company = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->type->id]);
    });

    it('cannot be deleted by a member', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(ListCompanies::class)
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionHidden('delete', $this->company)
            ->assertTableBulkActionHidden('delete');
    });

    it('can be deleted in bulk by an admin', function () {
        ($this->actAs)(OrganizationRole::Admin);

        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('delete', [$this->company]);

        expect($this->company->fresh()->trashed())->toBeTrue();
    });

    it('lets members attach and detach contacts, and viewers do neither', function (OrganizationRole $role, bool $allowed) {
        ($this->actAs)($role);
        $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->company->contacts()->attach($contact);

        $page = Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $this->company, 'pageClass' => EditCompany::class]);

        $allowed ? $page->assertActionVisible(TestAction::make(AttachAction::class)->table()) : $page->assertActionHidden(TestAction::make(AttachAction::class)->table());
        $allowed ? $page->assertTableActionVisible(DetachAction::class, $contact) : $page->assertTableActionHidden(DetachAction::class, $contact);
        $allowed ? $page->assertTableBulkActionVisible(DetachBulkAction::class) : $page->assertTableBulkActionHidden(DetachBulkAction::class);
    })->with([
        'viewer' => [OrganizationRole::Viewer, false],
        'member' => [OrganizationRole::Member, true],
    ]);

    it('is not editable by a viewer', function () {
        ($this->actAs)(OrganizationRole::Viewer);

        $this->get(CompanyResource::getUrl('create'))->assertForbidden();
        $this->get(CompanyResource::getUrl('edit', ['record' => $this->company]))->assertForbidden();
        $this->get(CompanyResource::getUrl('index'))->assertOk();
    });

    it('gives the inline company type creation to admins only', function (OrganizationRole $role, bool $visible) {
        ($this->actAs)($role);

        $page = Livewire::test(CreateCompany::class);
        $visible
            ? $page->assertActionVisible(TestAction::make('createOption')->schemaComponent('company_type_id'))
            : $page->assertActionHidden(TestAction::make('createOption')->schemaComponent('company_type_id'));
    })->with([
        'member' => [OrganizationRole::Member, false],
        'admin' => [OrganizationRole::Admin, true],
    ]);
});

describe('custom fields', function () {
    beforeEach(function () {
        $this->field = CustomField::factory()->create(['organization_id' => $this->org->id]);
    });

    it('are managed by admins only', function (OrganizationRole $role, bool $manage) {
        ($this->actAs)($role);

        $page = Livewire::test(ListCustomFields::class)->assertSuccessful();
        $manage ? $page->assertActionVisible(CreateAction::class) : $page->assertActionHidden(CreateAction::class);
        $manage ? $page->assertTableActionVisible(EditAction::class, $this->field) : $page->assertTableActionHidden(EditAction::class, $this->field);
        $manage ? $page->assertTableActionVisible('delete', $this->field) : $page->assertTableActionHidden('delete', $this->field);
    })->with([
        'viewer' => [OrganizationRole::Viewer, false],
        'member' => [OrganizationRole::Member, false],
        'admin' => [OrganizationRole::Admin, true],
    ]);

    it('cannot be created or edited by a member through the pages', function () {
        ($this->actAs)(OrganizationRole::Member);

        $this->get(CustomFieldResource::getUrl('create'))->assertForbidden();
        $this->get(CustomFieldResource::getUrl('edit', ['record' => $this->field]))->assertForbidden();
        $this->get(CustomFieldResource::getUrl('index'))->assertOk();
    });
});

describe('segments', function () {
    beforeEach(function () {
        $this->segment = Segment::factory()->published()->create([
            'organization_id' => $this->org->id,
            'draft_rules' => null,
        ]);
    });

    it('lets a member edit a draft but not save or publish it', function () {
        ($this->actAs)(OrganizationRole::Member);
        $segment = Segment::factory()->published()->withRules([authorizationRuleForPermissions()])->create(['organization_id' => $this->org->id]);

        $page = Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()])
            ->callAction(TestAction::make('createRule'), ['name' => 'Partners']);

        $segment->refresh();
        $draftNames = collect($segment->draft_rules)->pluck('name')->all();

        expect($draftNames)->toContain('Partners');

        $page->assertActionHidden('saveChanges')
            ->assertActionHidden('publish')
            ->assertActionVisible('cancelChanges');

        ($this->refused)($page, 'saveChanges');

        expect($segment->fresh()->rules)->toBe($segment->rules);
    });

    it('is published by an admin', function () {
        ($this->actAs)(OrganizationRole::Admin);
        $draft = Segment::factory()->withRules([authorizationRuleForPermissions()])->create(['organization_id' => $this->org->id, 'is_published' => false]);

        Livewire::test(ViewSegment::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('publish')
            ->callAction('publish');

        expect($draft->fresh()->is_published)->toBeTrue();
    });

    it('cannot be published by a member, even by calling the action', function () {
        ($this->actAs)(OrganizationRole::Member);
        $draft = Segment::factory()->withRules([authorizationRuleForPermissions()])->create(['organization_id' => $this->org->id, 'is_published' => false]);

        $page = Livewire::test(ViewSegment::class, ['record' => $draft->getRouteKey()])
            ->assertActionHidden('publish');
        ($this->refused)($page, 'publish');

        expect($draft->fresh()->is_published)->toBeFalse();
    });

    it('cannot be deleted by a member', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(ViewSegment::class, ['record' => $this->segment->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);
    });
});

describe('deals and invoices', function () {
    beforeEach(function () {
        $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->deal = Deal::factory()->create([
            'organization_id' => $this->org->id, 'contact_id' => $this->contact->id, 'created_by' => $this->owner->id,
            'stage' => DealStage::Negotiating, 'status' => DealStatus::Open, 'position' => '1000.0000000000',
        ]);
        $this->invoice = Invoice::factory()->create([
            'organization_id' => $this->org->id, 'contact_id' => $this->contact->id, 'deal_id' => $this->deal->id, 'status' => InvoiceStatus::Sent,
        ]);
    });

    it('does not let a viewer move a card of the pipeline', function () {
        ($this->actAs)(OrganizationRole::Viewer);

        Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $this->deal->id, DealStage::Won->value)
            ->assertForbidden();

        expect($this->deal->fresh()->stage)->toBe(DealStage::Negotiating)
            ->and($this->deal->fresh()->status)->toBe(DealStatus::Open);
    });

    it('lets a member move a card of the pipeline', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $this->deal->id, DealStage::Won->value);

        expect($this->deal->fresh()->stage)->toBe(DealStage::Won);
    });

    it('does not let a member move a card of another organization', function () {
        ($this->actAs)(OrganizationRole::Member);
        $other = Deal::factory()->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id, 'stage' => DealStage::Negotiating]);

        Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $other->id, DealStage::Won->value);

        expect($other->fresh()->stage)->toBe(DealStage::Negotiating);
    });

    it('shows a viewer no editing action on a deal', function () {
        ($this->actAs)(OrganizationRole::Viewer);

        Livewire::test(ViewDeal::class, ['record' => $this->deal->getRouteKey()])
            ->assertActionHidden('moveToWon')
            ->assertActionHidden('moveToLost')
            ->assertActionHidden('logActivity')
            ->assertActionHidden('createInvoice')
            ->assertActionHidden('quickTask')
            ->assertActionHidden('edit');
    });

    it('lets a member close a deal and create an invoice', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(ViewDeal::class, ['record' => $this->deal->getRouteKey()])
            ->assertActionVisible('createInvoice')
            ->callAction('moveToWon');

        expect($this->deal->fresh()->status)->toBe(DealStatus::Won);
    });

    it('gives each role its actions on an invoice', function (OrganizationRole $role, bool $edit, bool $cancel) {
        ($this->actAs)($role);
        $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])->assertActionVisible('downloadPdf');

        $edit ? $page->assertActionVisible('markAsPaid') : $page->assertActionHidden('markAsPaid');
        $cancel ? $page->assertActionVisible('cancelInvoice') : $page->assertActionHidden('cancelInvoice');
    })->with([
        'viewer' => [OrganizationRole::Viewer, false, false],
        'member' => [OrganizationRole::Member, true, false],
        'admin' => [OrganizationRole::Admin, true, true],
    ]);

    it('cannot be cancelled by a member, even by calling the action', function () {
        ($this->actAs)(OrganizationRole::Member);

        $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()]);
        ($this->refused)($page, 'cancelInvoice');

        expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Sent);
    });

    it('can be cancelled by an admin', function () {
        ($this->actAs)(OrganizationRole::Admin);

        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->getRouteKey()])->callAction('cancelInvoice');

        expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled);
    });
});

describe('tasks', function () {
    beforeEach(function () {
        $this->task = Task::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->owner->id, 'status' => TaskStatus::Pending, 'due_at' => now()->addDay()]);
    });

    it('can be completed or snoozed by a member but not by a viewer', function (OrganizationRole $role, bool $allowed) {
        ($this->actAs)($role);

        $page = Livewire::test(ListTasks::class);
        $allowed ? $page->assertTableActionVisible('markDone', $this->task) : $page->assertTableActionHidden('markDone', $this->task);
        $allowed ? $page->assertActionVisible(CreateAction::class) : $page->assertActionHidden(CreateAction::class);

        $edit = fn () => Livewire::test(EditTask::class, ['record' => $this->task->getRouteKey()]);
        $allowed ? $edit()->assertActionVisible('markDone') : $this->get(TaskResource::getUrl('edit', ['record' => $this->task]))->assertForbidden();
    })->with([
        'viewer' => [OrganizationRole::Viewer, false],
        'member' => [OrganizationRole::Member, true],
    ]);

    it('cannot be deleted by a member', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(ListTasks::class)
            ->assertTableActionHidden('delete', $this->task)
            ->assertTableBulkActionHidden(DeleteBulkAction::class);
    });

    it('cannot be completed by a viewer, even by calling the action', function () {
        ($this->actAs)(OrganizationRole::Viewer);

        $page = Livewire::test(ListTasks::class);
        $page->call('mountTableAction', 'markDone', (string) $this->task->getKey())->assertActionNotMounted();
        $page->call('callMountedTableAction');

        expect($this->task->fresh()->status)->toBe(TaskStatus::Pending);
    });
});

describe('quick tasks', function () {
    it('are not offered to a viewer, and calling the action creates nothing', function () {
        ($this->actAs)(OrganizationRole::Viewer);

        $page = Livewire::test(QuickTaskFloatingButton::class)
            ->assertActionHidden('quickTask')
            ->assertDontSee('Quick Task');
        ($this->refused)($page, 'quickTask');

        expect(Task::count())->toBe(0);
    });

    it('are offered to a member', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(QuickTaskFloatingButton::class)
            ->assertActionVisible('quickTask')
            ->assertSee('Quick Task');
    });
});

function authorizationRuleForPermissions(): SegmentRuleData
{
    return new SegmentRuleData('rule-1', 'Leads', [
        SegmentConditionData::make(
            SegmentConditionType::Attribute,
            ContactAttribute::Status->value,
            SegmentOperator::Is,
            ['value' => ContactStatus::Lead->value],
        ),
    ]);
}
