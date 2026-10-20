<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\LostReasons\LostReasonResource;
use App\Filament\Resources\LostReasons\Pages\ManageLostReasons;
use App\Filament\Resources\PipelineStages\PipelineStageResource;
use App\Models\LostReason;
use App\Models\Organization;
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

    $this->price = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Price too high']);
    $this->budget = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'No budget']);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->nameError = fn ($page): array => $page->errors()->get('mountedActions.0.data.name');
});

it('lists the reasons of the organization by name', function () {
    $this->get(LostReasonResource::getUrl('index'))->assertOk();

    Livewire::test(ManageLostReasons::class)
        ->assertCanSeeTableRecords([$this->budget, $this->price], inOrder: true);
});

it('does not list the reasons of another organization', function () {
    $foreign = LostReason::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Foreign']);

    Livewire::test(ManageLostReasons::class)->assertCanNotSeeTableRecords([$foreign]);
});

it('is in the Organization group right after the pipeline stages and is not globally searchable', function () {
    expect(LostReasonResource::getNavigationLabel())->toBe('Lost reasons')
        ->and(LostReasonResource::getNavigationGroup())->toBe('Organization')
        ->and(LostReasonResource::getNavigationSort())->toBe(21)
        ->and(LostReasonResource::getNavigationSort())->toBeGreaterThan(PipelineStageResource::getNavigationSort())
        ->and(LostReasonResource::canGloballySearch())->toBeFalse();
});

it('shows an empty state that explains the list', function () {
    LostReason::query()->delete();

    Livewire::test(ManageLostReasons::class)
        ->assertSee('No lost reasons yet')
        ->assertSee('Picking a reason will stay optional.');
});

describe('creating and renaming', function () {
    it('creates a reason with a trimmed name in the organization', function () {
        Livewire::test(ManageLostReasons::class)
            ->callAction(CreateAction::class, ['name' => '  Went with a competitor  '])
            ->assertHasNoActionErrors();

        expect(LostReason::query()->where('name', 'Went with a competitor')->sole()->organization_id)->toBe($this->org->id);
    });

    it('refuses a bad name and creates nothing', function (string $name, string $rule) {
        $page = Livewire::test(ManageLostReasons::class)->callAction(CreateAction::class, ['name' => $name]);

        if ($rule === 'unique') {
            expect(($this->nameError)($page))->toContain('Another lost reason already has this name.');
        } else {
            $page->assertHasActionErrors(['name' => $rule]);
        }

        expect(LostReason::query()->where('organization_id', $this->org->id)->count())->toBe(2);
    })->with([
        'empty' => ['', 'required'],
        'spaces only' => ['   ', 'required'],
        '101 characters' => [str_repeat('a', 101), 'max'],
        'same name, other case' => ['price too high', 'unique'],
        'same name, padded and upper-cased' => [' NO BUDGET ', 'unique'],
    ]);

    it('accepts 100 characters, a name of another organization and the name of a deleted reason', function () {
        LostReason::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Bad timing']);
        $this->budget->delete();

        foreach ([str_repeat('a', 100), 'Bad timing', 'No budget'] as $name) {
            Livewire::test(ManageLostReasons::class)
                ->callAction(CreateAction::class, ['name' => $name])
                ->assertHasNoActionErrors();
        }

        expect(LostReason::withTrashed()->where('organization_id', $this->org->id)->count())->toBe(5);
    });

    it('refuses the name of a reason stored with surrounding spaces', function () {
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => ' Slow reply ']);

        $page = Livewire::test(ManageLostReasons::class)->callAction(CreateAction::class, ['name' => 'slow reply']);

        expect(($this->nameError)($page))->toContain('Another lost reason already has this name.');
    });

    it('renames a reason, including to its own name in another case', function () {
        Livewire::test(ManageLostReasons::class)
            ->callAction(TestAction::make(EditAction::class)->table($this->budget), ['name' => 'No budget left'])
            ->assertHasNoActionErrors();

        Livewire::test(ManageLostReasons::class)
            ->callAction(TestAction::make(EditAction::class)->table($this->price), ['name' => 'price too high'])
            ->assertHasNoActionErrors();

        expect($this->budget->fresh()->name)->toBe('No budget left')
            ->and($this->price->fresh()->name)->toBe('price too high');
    });

    it('refuses to rename to the name of another reason and keeps the old name', function () {
        $page = Livewire::test(ManageLostReasons::class)
            ->callAction(TestAction::make(EditAction::class)->table($this->price), ['name' => 'no budget']);

        expect(($this->nameError)($page))->toContain('Another lost reason already has this name.')
            ->and($this->price->fresh()->name)->toBe('Price too high');
    });
});

describe('deleting', function () {
    it('asks for a confirmation that explains what happens to the deals', function () {
        Livewire::test(ManageLostReasons::class)
            ->assertTableActionExists('delete', fn (DeleteAction $action): bool => $action->getModalDescription() === 'The reason is no longer offered when a deal is lost. Deals that already have it keep it, shown as "(deleted)".'
                && $action->isConfirmationRequired(), $this->budget);
    });

    it('soft deletes a reason', function () {
        Livewire::test(ManageLostReasons::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($this->budget))
            ->assertHasNoActionErrors();

        expect($this->budget->fresh()->trashed())->toBeTrue()
            ->and($this->budget->fresh()->lostReasonLabel())->toBe('No budget (deleted)')
            ->and($this->price->fresh()->lostReasonLabel())->toBe('Price too high');
    });

    it('soft deletes several reasons at once', function () {
        Livewire::test(ManageLostReasons::class)
            ->selectTableRecords([$this->price, $this->budget])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertHasNoActionErrors();

        expect(LostReason::query()->count())->toBe(0)
            ->and(LostReason::onlyTrashed()->count())->toBe(2);
    });
});

describe('restoring', function () {
    it('restores a reason', function () {
        $this->budget->delete();

        Livewire::test(ManageLostReasons::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make(RestoreAction::class)->table($this->budget))
            ->assertHasNoActionErrors();

        expect($this->budget->fresh()->trashed())->toBeFalse();
    });

    it('is refused when an active reason has the same name', function () {
        $this->budget->delete();
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'no budget']);

        Livewire::test(ManageLostReasons::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make(RestoreAction::class)->table($this->budget))
            ->assertNotified('A lost reason named "No budget" already exists. Rename one of them first.');

        expect($this->budget->fresh()->trashed())->toBeTrue();
    });

    it('restores several reasons and keeps the ones whose name is taken', function () {
        $this->budget->delete();
        $this->price->delete();
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Price too high']);

        Livewire::test(ManageLostReasons::class)
            ->filterTable('trashed', true)
            ->selectTableRecords([$this->price, $this->budget])
            ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

        $notifications = new Notifications;
        $notifications->mount();

        expect($this->budget->fresh()->trashed())->toBeFalse()
            ->and($this->price->fresh()->trashed())->toBeTrue()
            ->and($notifications->notifications->last()->getBody())->toContain('A lost reason named "Price too high" already exists. Rename one of them first.');
    });

    it('shows the count when several reasons are kept deleted', function () {
        $this->budget->delete();
        $this->price->delete();
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'price too high']);
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'no budget']);

        Livewire::test(ManageLostReasons::class)
            ->filterTable('trashed', true)
            ->selectTableRecords([$this->price, $this->budget])
            ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

        $notifications = new Notifications;
        $notifications->mount();

        expect(LostReason::onlyTrashed()->count())->toBe(2)
            ->and($notifications->notifications->last()->getBody())->toContain('2 reasons were kept deleted because an active reason with the same name already exists.');
    });

    it('does not let the notification of a restore render markup', function () {
        $payload = '<a href="https://evil.test">x</a>';
        $reason = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        $reason->delete();
        LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);

        Livewire::test(ManageLostReasons::class)
            ->filterTable('trashed', true)
            ->callAction(TestAction::make(RestoreAction::class)->table($reason));

        $notifications = new Notifications;
        $notifications->mount();

        expect($notifications->notifications->last()->getTitle())->not->toContain('<a href');
    });
});

describe('who can manage them', function () {
    it('lets admins and the owner manage the reasons', function (OrganizationRole $role) {
        ($this->actAs)($role);
        $this->budget->delete();

        Livewire::test(ManageLostReasons::class)
            ->assertSuccessful()
            ->assertActionVisible(CreateAction::class)
            ->assertTableActionVisible(EditAction::class, $this->price)
            ->assertTableActionVisible('delete', $this->price)
            ->filterTable('trashed', true)
            ->assertTableActionVisible('restore', $this->budget);
    })->with([OrganizationRole::Admin, OrganizationRole::Owner]);

    it('shows members and viewers the list read-only', function (OrganizationRole $role) {
        ($this->actAs)($role);
        $this->budget->delete();

        Livewire::test(ManageLostReasons::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->price])
            ->assertActionHidden(CreateAction::class)
            ->assertTableActionHidden(EditAction::class, $this->price)
            ->assertTableActionHidden('delete', $this->price)
            ->filterTable('trashed', true)
            ->assertTableActionHidden('restore', $this->budget);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('writes nothing for a forged create or bulk delete by a member', function () {
        ($this->actAs)(OrganizationRole::Member);

        Livewire::test(ManageLostReasons::class)
            ->call('mountAction', 'create')
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Livewire::test(ManageLostReasons::class)
            ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        expect(LostReason::query()->where('name', 'Forged')->exists())->toBeFalse()
            ->and(LostReason::query()->count())->toBe(2);
    });
});
