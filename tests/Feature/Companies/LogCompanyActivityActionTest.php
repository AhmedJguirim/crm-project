<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\OrganizationRole;
use App\Filament\Actions\LogCompanyActivityAction;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Jobs\ResyncContactSegments;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech']);
    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'company_id' => $this->company->id,
        'created_by' => $this->user->id,
        'title' => 'Initech retainer',
    ]);
    $this->hooli = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Hooli']);
    $this->hooliDeal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'company_id' => $this->hooli->id,
        'created_by' => $this->user->id,
        'title' => 'Hooli pilot',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the action is on the company page and prefilled', function () {
    $hasNow = function (array $state): array {
        expect(Carbon::parse($state['occurred_at'])->toDateTimeString())->toBe('2026-11-09 12:00:00');

        return [];
    };

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertActionVisible('logActivity')
        ->assertActionHasLabel('logActivity', 'Log Activity')
        ->mountAction('logActivity')
        ->assertActionDataSet($hasNow);

    expect(LogCompanyActivityAction::make()->getName())->toBe('logActivity')
        ->and(LogCompanyActivityAction::make('other')->getName())->toBe('other');
});

test('logging stores a company-only activity', function () {
    Queue::fake();

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => '2026-11-09 10:00:00',
            'subject' => 'Intro call',
            'outcome' => ActivityOutcome::Positive,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Activity logged')
        ->assertDispatched('activityLogged');

    $activity = Activity::query()->where('subject', 'Intro call')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($this->company->id)
        ->and($activity->contact_id)->toBeNull()
        ->and($activity->deal_id)->toBeNull()
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->organization_id)->toBe($this->org->id)
        ->and($activity->type)->toBe(ActivityType::Call)
        ->and($activity->outcome)->toBe(ActivityOutcome::Positive);

    Queue::assertNotPushed(ResyncContactSegments::class);
});

test('logging with one of the company deals links both', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Meeting,
            'occurred_at' => '2026-11-09 09:00:00',
            'duration_minutes' => 30,
            'subject' => 'Scope review',
            'notes' => 'Agreed the scope',
            'deal_id' => $this->deal->id,
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->where('subject', 'Scope review')->first();

    expect($activity->company_id)->toBe($this->company->id)
        ->and($activity->deal_id)->toBe($this->deal->id)
        ->and($activity->contact_id)->toBeNull()
        ->and($activity->duration_minutes)->toBe(30)
        ->and($activity->notes)->toBe('Agreed the scope');
});

test('a deal that is not one of the company deals is refused', function (string $which) {
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Deal::factory()->create(['organization_id' => $otherOrg->id, 'title' => 'Globex deal']);

    $dealId = match ($which) {
        'another company' => $this->hooliDeal->id,
        'another organization' => $foreign->id,
        default => $this->deal->id,
    };

    $page = Livewire::test(EditCompany::class, ['record' => $this->company->id])->mountAction('logActivity');

    if ($which === 'deleted while open') {
        $this->deal->delete();
    }

    $page->setActionData([
        'type' => ActivityType::Call,
        'occurred_at' => '2026-11-09 10:00:00',
        'subject' => 'Bad link',
        'deal_id' => $dealId,
    ])->callMountedAction()
        ->assertHasActionErrors(['deal_id']);

    expect(Activity::query()->where('subject', 'Bad link')->exists())->toBeFalse();
})->with(['another company', 'another organization', 'deleted while open']);

test('a padded or array deal id is refused without a server error', function (mixed $forged) {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => '2026-11-09 10:00:00',
            'subject' => 'Forged',
            'deal_id' => $forged,
        ])
        ->assertHasActionErrors(['deal_id']);

    expect(Activity::query()->where('subject', 'Forged')->exists())->toBeFalse();
})->with(fn () => [' 1', ['1']]);

test('the deal choices are the company deals only', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->mountAction('logActivity')
        ->assertSchemaComponentExists('deal_id', checkComponentUsing: fn ($select): bool => $select->getOptions() === [$this->deal->id => 'Initech retainer']);
});

test('the action is shown to members, admins and owners', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->org);

    Livewire::test(EditCompany::class, ['record' => $this->company->id])->assertActionVisible('logActivity');
})->with([OrganizationRole::Member, OrganizationRole::Admin, OrganizationRole::Owner]);

test('the action is hidden on a soft-deleted company', function () {
    $this->company->delete();

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertActionHidden('logActivity')
        ->call('mountAction', 'logActivity')
        ->assertActionNotMounted();
});

test('another organization company can not receive an activity', function () {
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Company::factory()->create(['organization_id' => $otherOrg->id]);

    Livewire::test(EditCompany::class, ['record' => $foreign->id])->assertNotFound();
});
