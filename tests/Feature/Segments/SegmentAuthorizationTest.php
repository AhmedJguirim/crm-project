<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Policies\SegmentPolicy;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * A stand-in policy for users who may do everything with segments except update them.
 */
class DenySegmentUpdatePolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function authorizationRule(): SegmentRuleData
{
    return new SegmentRuleData('rule-1', 'Leads', [
        new SegmentConditionData('condition-1', SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
    ]);
}

describe('segment policy', function () {
    it('lets the members of the organization manage its segments', function () {
        $segment = Segment::factory()->for($this->org)->create();

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($this->user)->allows($ability, $segment))->toBeTrue();
        }

        expect(Gate::forUser($this->user)->allows('viewAny', Segment::class))->toBeTrue()
            ->and(Gate::forUser($this->user)->allows('create', Segment::class))->toBeTrue();
    });

    it('denies the segments of another organization', function () {
        $segment = Segment::factory()->for(Organization::factory()->create())->create();

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($this->user)->allows($ability, $segment))->toBeFalse();
        }
    });

    it('is discovered without being registered', function () {
        expect(Gate::getPolicyFor(Segment::class))->toBeInstanceOf(SegmentPolicy::class);
    });
});

it('does not look up the membership once per row of the segments list', function () {
    Segment::factory()->for($this->org)->count(10)->create();

    DB::enableQueryLog();
    Livewire::test(ListSegments::class);

    $membershipQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'organization_user'))->count();

    expect($membershipQueries)->toBeLessThanOrEqual(6);
});

describe('rule editor', function () {
    it('is forbidden when the user may not update the segment', function () {
        $segment = Segment::factory()->for($this->org)->withRules([authorizationRule()])->create();
        Gate::policy(Segment::class, DenySegmentUpdatePolicy::class);

        $this->get(SegmentResource::getUrl('rules', ['record' => $segment]))->assertForbidden();
    });

    it('hides and blocks every mutating action once the user may no longer update', function (string $actionName, array $arguments, string $state) {
        $segment = match ($state) {
            'draft' => Segment::factory()->for($this->org)->withRules([authorizationRule()])->create(),
            'pending changes' => Segment::factory()->for($this->org)->published()->withRules([authorizationRule()])->create([
                'draft_rules' => [(new SegmentRuleData('rule-2', 'Partners', []))->toArray()],
            ]),
        };
        $before = $segment->fresh()->only(['name', 'rules', 'draft_rules', 'is_published']);
        $action = TestAction::make($actionName)->arguments($arguments);

        $page = Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()]);
        $page->assertActionVisible($action);

        Gate::policy(Segment::class, DenySegmentUpdatePolicy::class);

        $page->assertActionHidden($action)
            ->call('mountAction', $actionName, $arguments, [])
            ->assertActionNotMounted();

        expect($segment->fresh()->only(['name', 'rules', 'draft_rules', 'is_published']))->toBe($before);
    })->with([
        'create a rule' => ['createRule', [], 'draft'],
        'rename a rule' => ['renameRule', ['rule' => 'rule-1'], 'draft'],
        'delete a rule' => ['deleteRule', ['rule' => 'rule-1'], 'draft'],
        'add a condition' => ['addCondition', ['rule' => 'rule-1'], 'draft'],
        'edit a condition' => ['editCondition', ['rule' => 'rule-1', 'condition' => 'condition-1'], 'draft'],
        'delete a condition' => ['deleteCondition', ['rule' => 'rule-1', 'condition' => 'condition-1'], 'draft'],
        'save the changes' => ['saveChanges', [], 'pending changes'],
        'cancel the changes' => ['cancelChanges', [], 'pending changes'],
        'publish' => ['publish', [], 'draft'],
        'rename the segment' => ['rename', [], 'draft'],
    ]);

    it('still lets a member edit the rules', function () {
        $segment = Segment::factory()->for($this->org)->withRules([new SegmentRuleData('rule-1', 'Rule', [])])->create();

        Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()])
            ->callAction(
                TestAction::make('addCondition')->arguments(['rule' => 'rule-1']),
                ['type' => 'attribute', 'field' => 'status', 'operator' => 'is', 'option_value' => 'lead'],
            )
            ->assertHasNoActionErrors();

        expect($segment->fresh()->workingRules()->sole()->conditions)->toHaveCount(1);
    });
});

describe('segment page', function () {
    it('hides publish for users who may not update', function () {
        $segment = Segment::factory()->for($this->org)->withRules([authorizationRule()])->create();

        $page = Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()]);
        $page->assertActionVisible('publish');

        Gate::policy(Segment::class, DenySegmentUpdatePolicy::class);

        $page->assertActionHidden('publish');
    });
});
