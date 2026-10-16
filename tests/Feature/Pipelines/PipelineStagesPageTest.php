<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\PipelineStages\Pages\ManagePipelineStages;
use App\Filament\Resources\PipelineStages\PipelineStageResource;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use App\Services\Segments\SegmentQueryBuilder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Support\Facades\FilamentColor;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->stage = fn (string $name): PipelineStage => pipelineStageNamed($this->org->id, $name)->refresh();
    $this->retainer = Deal::factory()->inStage('Lead')->create(['organization_id' => $this->org->id, 'title' => 'Retainer']);
    $this->audit = Deal::factory()->inStage('Won')->create(['organization_id' => $this->org->id, 'title' => 'Audit', 'status' => DealStatus::Won]);

    $this->edit = fn (string $stage, array $data) => Livewire::test(ManagePipelineStages::class)
        ->callAction(TestAction::make(EditAction::class)->table(($this->stage)($stage)), $data);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };
});

it('lists the stages in board order with their type, probability and deals', function () {
    $this->get(PipelineStageResource::getUrl('index'))->assertOk();

    $page = Livewire::test(ManagePipelineStages::class);
    $stages = collect(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost'])->map(fn (string $name): PipelineStage => ($this->stage)($name));

    $page->assertCanSeeTableRecords($stages, inOrder: true)
        ->assertTableColumnStateSet('probability', 10, ($this->stage)('Lead'))
        ->assertTableColumnStateSet('probability', 100, ($this->stage)('Won'))
        ->assertTableColumnFormattedStateSet('probability', '10%', ($this->stage)('Lead'))
        ->assertTableColumnFormattedStateSet('probability', '100%', ($this->stage)('Won'))
        ->assertTableColumnStateSet('deals_count', 1, ($this->stage)('Lead'))
        ->assertTableColumnStateSet('deals_count', 1, ($this->stage)('Won'))
        ->assertTableColumnStateSet('deals_count', 0, ($this->stage)('Discovery'))
        ->assertTableColumnStateSet('type', DealStatus::Won, ($this->stage)('Won'));
});

it('sits in the Organization group and stays out of the global search', function () {
    expect(PipelineStageResource::getNavigationLabel())->toBe('Pipeline stages')
        ->and(PipelineStageResource::getNavigationGroup())->toBe('Organization')
        ->and(PipelineStageResource::getNavigationSort())->toBe(20)
        ->and(PipelineStageResource::canGloballySearch())->toBeFalse();
});

describe('scope', function () {
    it('does not show or open the stages of another organization', function () {
        $globex = Organization::factory()->create();
        $foreign = pipelineStageNamed($globex, 'Lead');

        Livewire::test(ManagePipelineStages::class)
            ->assertCanNotSeeTableRecords([$foreign])
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $foreign->id])
            ->assertActionNotMounted();

        expect($foreign->fresh()->name)->toBe('Lead');
    });

    it('does not list the stages of a second pipeline of the organization', function () {
        $second = Pipeline::factory()->create(['organization_id' => $this->org->id]);
        $other = PipelineStage::factory()->create(['pipeline_id' => $second->id, 'name' => 'Other']);

        Livewire::test(ManagePipelineStages::class)
            ->assertCanNotSeeTableRecords([$other])
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $other->id])
            ->assertActionNotMounted();

        expect($other->fresh()->name)->toBe('Other');
    });
});

describe('renaming', function () {
    it('renames an open stage, trimmed, and the board and the stage options follow', function () {
        ($this->edit)('Discovery', ['name' => '  Qualified  ', 'color' => 'info', 'probability' => 25])->assertHasNoActionErrors();

        $options = DefaultPipeline::stageOptions($this->org->id);
        $columns = collect(Livewire::test(DealPipeline::class)->instance()->getBoard()->getColumns())->map(fn ($column): string => $column->getLabel());

        expect(PipelineStage::query()->where('organization_id', $this->org->id)->where('name', 'Qualified')->exists())->toBeTrue()
            ->and($options)->toContain('Qualified')->not->toContain('Discovery')
            ->and($columns->all())->toContain('Qualified')->not->toContain('Discovery');
    });

    it('renames Won and its type stays Won', function () {
        ($this->edit)('Won', ['name' => 'Closed won', 'color' => 'success'])->assertHasNoActionErrors();

        $won = DefaultPipeline::stageOfType($this->org->id, DealStatus::Won);

        expect($won->name)->toBe('Closed won')->and($won->type)->toBe(DealStatus::Won);
    });

    it('refuses a bad name and keeps the old one', function (string $name, string $rule) {
        $page = ($this->edit)('Discovery', ['name' => $name, 'color' => 'info', 'probability' => 25]);

        if ($rule === 'unique') {
            expect($page->errors()->get('mountedActions.0.data.name'))->toContain('Another stage of the pipeline already has this name.');
        } else {
            $page->assertHasActionErrors(['name' => $rule]);
        }

        expect(($this->stage)('Discovery')->name)->toBe('Discovery');
    })->with([
        'empty' => ['', 'required'],
        'spaces only' => ['   ', 'required'],
        '51 characters' => [str_repeat('a', 51), 'max'],
        'same name as Lead, other case' => ['lead', 'unique'],
        'same name as Proposal Sent, padded and upper-cased' => [' PROPOSAL SENT ', 'unique'],
    ]);

    it('accepts 50 characters and its own name in another case', function () {
        ($this->edit)('Discovery', ['name' => str_repeat('a', 50), 'color' => 'info', 'probability' => 25])->assertHasNoActionErrors();
        expect(($this->stage)(str_repeat('a', 50))->name)->toHaveLength(50);

        ($this->edit)('Lead', ['name' => 'lead', 'color' => 'gray', 'probability' => 10])->assertHasNoActionErrors();
        expect(PipelineStage::query()->where('organization_id', $this->org->id)->where('name', 'lead')->exists())->toBeTrue();
    });

    it('refuses the name of a padded stage, while the stages of another organization never clash', function () {
        $globex = Organization::factory()->create();
        pipelineStageNamed($globex, 'Discovery')->update(['name' => 'Pitch']);

        ($this->edit)('Lead', ['name' => 'Pitch', 'color' => 'gray', 'probability' => 10])->assertHasNoActionErrors();
        expect(PipelineStage::query()->where('organization_id', $this->org->id)->where('name', 'Pitch')->exists())->toBeTrue();

        pipelineStageNamed($this->org, 'Negotiating')->update(['name' => 'Negotiating ']);
        $page = ($this->edit)('Pitch', ['name' => 'Negotiating', 'color' => 'gray', 'probability' => 10]);

        expect($page->errors()->get('mountedActions.0.data.name'))->toContain('Another stage of the pipeline already has this name.');
    });

    it('keeps the deals and the segment conditions of a renamed stage', function () {
        $won = ($this->stage)('Won');
        $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
        $this->audit->update(['contact_id' => $contact->id]);
        $rule = new SegmentRuleData('rule-1', 'Won deal', [
            SegmentConditionData::make(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_stages' => [$won->id]]),
        ]);
        $members = fn (): array => SegmentQueryBuilder::forOrganization($this->org->id)->matching([$rule])->pluck('contacts.id')->all();

        expect($members())->toBe([$contact->id]);

        ($this->edit)('Won', ['name' => 'Closed won', 'color' => 'success'])->assertHasNoActionErrors();

        expect($this->audit->fresh()->pipeline_stage_id)->toBe($won->id)
            ->and($members())->toBe([$contact->id]);
    });
});

describe('color', function () {
    it('changes the color, which the deals list badge uses', function () {
        ($this->edit)('Lead', ['name' => 'Lead', 'color' => 'danger', 'probability' => 10])->assertHasNoActionErrors();

        expect(($this->stage)('Lead')->color)->toBe('danger');

        $column = Livewire::test(ListDeals::class)->instance()->getTable()->getColumn('pipelineStage.name');
        $column->record($this->retainer->fresh('pipelineStage'));

        expect($column->getColor($column->getState()))->toBe('danger');
    });

    it('refuses a color outside the list', function (string $color) {
        ($this->edit)('Lead', ['name' => 'Lead', 'color' => $color, 'probability' => 10])->assertHasActionErrors(['color']);

        expect(($this->stage)('Lead')->color)->toBe('gray');
    })->with(['javascript:alert(1)', 'purple']);

    it('only offers colors the panel registers', function () {
        $this->get(PipelineStageResource::getUrl('index'))->assertOk();

        foreach (array_keys(PipelineStage::COLORS) as $color) {
            expect(FilamentColor::getColor($color))->not->toBeNull($color);
        }
    });
});

describe('probability', function () {
    it('saves a probability between 0 and 100 on an open stage', function (int $value) {
        ($this->edit)('Discovery', ['name' => 'Discovery', 'color' => 'info', 'probability' => $value])->assertHasNoActionErrors();

        expect(($this->stage)('Discovery')->probability)->toBe($value);
    })->with([0, 40, 100]);

    it('refuses anything else and keeps 25', function (mixed $value) {
        ($this->edit)('Discovery', ['name' => 'Discovery', 'color' => 'info', 'probability' => $value])->assertHasActionErrors(['probability']);

        expect(($this->stage)('Discovery')->probability)->toBe(25);
    })->with([-1, 101, '12.5', 'abc']);

    it('fixes the probability of Won and Lost', function () {
        $helper = 'A won stage is always 100% and a lost stage 0%.';
        $probabilityField = fn (string $stage): Field => Livewire::test(ManagePipelineStages::class)
            ->mountAction(TestAction::make(EditAction::class)->table(($this->stage)($stage)))
            ->instance()->mountedActionSchema0->getFlatFields(withHidden: true)['probability'];

        foreach (['Won', 'Lost'] as $name) {
            $field = $probabilityField($name);

            expect($field->isDisabled())->toBeTrue()
                ->and(html_entity_decode(strip_tags((string) $field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString())))->toContain($helper);
        }

        $open = $probabilityField('Discovery');

        expect($open->isDisabled())->toBeFalse()
            ->and(strip_tags((string) $open->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString()))->not->toContain('always');

        ($this->edit)('Won', ['name' => 'Won', 'color' => 'success', 'probability' => 55])->assertHasNoActionErrors();
        ($this->edit)('Lost', ['name' => 'Lost', 'color' => 'gray', 'probability' => 30])->assertHasNoActionErrors();

        expect(($this->stage)('Won')->probability)->toBe(100)
            ->and(($this->stage)('Lost')->probability)->toBe(0);
    });
});

describe('who can edit', function () {
    it('lets every role see the list, and only admins and owners edit', function (OrganizationRole $role, bool $canEdit) {
        ($this->actAs)($role);

        $this->get(PipelineStageResource::getUrl('index'))->assertOk();

        $page = Livewire::test(ManagePipelineStages::class)->assertCanSeeTableRecords([($this->stage)('Lead')]);
        $canEdit
            ? $page->assertActionVisible(TestAction::make(EditAction::class)->table(($this->stage)('Lead')))
            : $page->assertActionHidden(TestAction::make(EditAction::class)->table(($this->stage)('Lead')));
    })->with([
        'owner' => [OrganizationRole::Owner, true],
        'admin' => [OrganizationRole::Admin, true],
        'member' => [OrganizationRole::Member, false],
        'viewer' => [OrganizationRole::Viewer, false],
    ]);

    it('refuses a member who forces the edit', function () {
        ($this->actAs)(OrganizationRole::Member);
        $lead = ($this->stage)('Lead');

        Livewire::test(ManagePipelineStages::class)
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $lead->id])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        expect(($this->stage)('Lead')->name)->toBe('Lead');
    });
});

describe('add a stage', function () {
    beforeEach(function () {
        $this->add = fn (array $data) => Livewire::test(ManagePipelineStages::class)->callAction(CreateAction::class, $data);
        $this->order = fn (): array => PipelineStage::query()->where('organization_id', $this->org->id)->orderBy('position')->pluck('position', 'name')->all();
    });

    it('adds an open stage before Won, trimmed, and the table, the board and the options follow', function () {
        ($this->add)(['name' => '  Qualified  ', 'color' => 'info', 'probability' => 60])->assertHasNoActionErrors()->assertNotified('Stage added');

        $qualified = ($this->stage)('Qualified');
        $names = ['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Qualified', 'Won', 'Lost'];
        $columns = collect(Livewire::test(DealPipeline::class)->instance()->getBoard()->getColumns())->map(fn ($column): string => $column->getLabel());

        expect($qualified->type)->toBe(DealStatus::Open)
            ->and($qualified->position)->toBe(5)
            ->and(($this->order)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Qualified' => 5, 'Won' => 6, 'Lost' => 7])
            ->and(array_values(array_intersect($columns->all(), $names)))->toBe($names)
            ->and(DefaultPipeline::stageOptions($this->org->id))->toContain('Qualified');

        Livewire::test(ManagePipelineStages::class)
            ->assertCanSeeTableRecords(collect($names)->map(fn (string $name): PipelineStage => ($this->stage)($name)), inOrder: true);
    });

    it('defaults the color to gray', function () {
        ($this->add)(['name' => 'Qualified', 'probability' => 60])->assertHasNoActionErrors();

        expect(($this->stage)('Qualified')->color)->toBe('gray');
    });

    it('refuses bad input and creates nothing', function (string $name, string $color, mixed $probability, string $field) {
        $page = ($this->add)(['name' => $name, 'color' => $color, 'probability' => $probability]);

        if ($field === 'unique') {
            expect($page->errors()->get('mountedActions.0.data.name'))->toContain('Another stage of the pipeline already has this name.');
        } else {
            $page->assertHasActionErrors([$field]);
        }

        expect(PipelineStage::query()->where('organization_id', $this->org->id)->count())->toBe(6);
    })->with([
        'empty name' => ['', 'gray', 50, 'name'],
        '51 characters' => [str_repeat('a', 51), 'gray', 50, 'name'],
        'name of Lead, padded' => [' lead ', 'gray', 50, 'unique'],
        'name of Won, upper-cased' => ['WON', 'gray', 50, 'unique'],
        'unknown color' => ['Qualified', 'purple', 50, 'color'],
        'no probability' => ['Qualified', 'gray', '', 'probability'],
        'probability 101' => ['Qualified', 'gray', 101, 'probability'],
        'decimal probability' => ['Qualified', 'gray', '12.5', 'probability'],
    ]);

    it('accepts 50 characters and a probability of 0 or 100', function () {
        ($this->add)(['name' => str_repeat('a', 50), 'color' => 'gray', 'probability' => 0])->assertHasNoActionErrors();
        ($this->add)(['name' => 'Sure thing', 'color' => 'gray', 'probability' => 100])->assertHasNoActionErrors();

        expect(PipelineStage::query()->where('organization_id', $this->org->id)->count())->toBe(8);
    });

    it('accepts a name used only in another organization', function () {
        $globex = Organization::factory()->create();
        pipelineStageNamed($globex, 'Discovery')->update(['name' => 'Qualified']);

        ($this->add)(['name' => 'Qualified', 'color' => 'gray', 'probability' => 60])->assertHasNoActionErrors();

        expect(PipelineStage::query()->where('organization_id', $this->org->id)->where('name', 'Qualified')->count())->toBe(1);
    });

    it('lets only admins and owners add a stage', function (OrganizationRole $role, bool $allowed) {
        ($this->actAs)($role);

        $page = Livewire::test(ManagePipelineStages::class);
        $allowed ? $page->assertActionVisible(CreateAction::class) : $page->assertActionHidden(CreateAction::class);
    })->with([
        'owner' => [OrganizationRole::Owner, true],
        'admin' => [OrganizationRole::Admin, true],
        'member' => [OrganizationRole::Member, false],
        'viewer' => [OrganizationRole::Viewer, false],
    ]);

    it('refuses a member who forces the create action', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(ManagePipelineStages::class)
            ->call('mountAction', 'create')
            ->assertActionNotMounted()
            ->call('callMountedAction');

        expect(PipelineStage::query()->where('organization_id', $this->org->id)->count())->toBe(6);
    });
});

describe('reorder', function () {
    beforeEach(function () {
        $this->order = fn (): array => PipelineStage::query()->where('organization_id', $this->org->id)->orderBy('position')->pluck('position', 'name')->all();
        $this->keys = fn (array $names): array => array_map(fn (string $name): string => (string) ($this->stage)($name)->id, $names);
    });

    it('reorders the open stages from the page without a notification', function () {
        $page = Livewire::test(ManagePipelineStages::class)
            ->call('reorderTable', ($this->keys)(['Negotiating', 'Lead', 'Discovery', 'Proposal Sent', 'Won', 'Lost']))
            ->assertNotNotified();

        expect(($this->order)())->toBe(['Negotiating' => 1, 'Lead' => 2, 'Discovery' => 3, 'Proposal Sent' => 4, 'Won' => 5, 'Lost' => 6]);

        $page->assertCanSeeTableRecords(
            collect(['Negotiating', 'Lead', 'Discovery', 'Proposal Sent', 'Won', 'Lost'])->map(fn (string $name): PipelineStage => ($this->stage)($name)),
            inOrder: true,
        );
        expect(Livewire::test(DealPipeline::class)->instance()->getBoard()->getColumns()[0]->getLabel())->toBe('Negotiating');
    });

    it('warns and snaps Won and Lost back when they are dragged up', function () {
        Livewire::test(ManagePipelineStages::class)
            ->call('reorderTable', ($this->keys)(['Won', 'Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Lost']))
            ->assertNotified('Won and Lost always stay at the end of the pipeline.');

        expect(($this->order)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
    });

    it('ignores the stages of another organization in the order', function () {
        $globex = Organization::factory()->create();
        $foreign = pipelineStageNamed($globex, 'Lead');

        Livewire::test(ManagePipelineStages::class)->call('reorderTable', [(string) $foreign->id, ...($this->keys)(['Negotiating'])]);

        expect(($this->order)())->toBe(['Negotiating' => 1, 'Lead' => 2, 'Discovery' => 3, 'Proposal Sent' => 4, 'Won' => 5, 'Lost' => 6])
            ->and($foreign->fresh()->position)->toBe(1);
    });

    it('lets only admins and owners reorder', function (OrganizationRole $role, bool $allowed) {
        ($this->actAs)($role);

        $page = Livewire::test(ManagePipelineStages::class)
            ->call('reorderTable', ($this->keys)(['Negotiating', 'Lead', 'Discovery', 'Proposal Sent', 'Won', 'Lost']));

        expect($page->instance()->getTable()->isReorderable())->toBe($allowed)
            ->and(($this->order)())->toBe($allowed
                ? ['Negotiating' => 1, 'Lead' => 2, 'Discovery' => 3, 'Proposal Sent' => 4, 'Won' => 5, 'Lost' => 6]
                : ['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
    })->with([
        'owner' => [OrganizationRole::Owner, true],
        'admin' => [OrganizationRole::Admin, true],
        'member' => [OrganizationRole::Member, false],
        'viewer' => [OrganizationRole::Viewer, false],
    ]);
});
