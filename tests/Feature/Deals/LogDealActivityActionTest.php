<?php

use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\OrganizationRole;
use App\Filament\Actions\LogDealActivityAction;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the deal page builds its action from the class', function () {
    $page = Livewire::test(ViewDeal::class, ['record' => $this->deal->id]);

    $page->assertActionVisible('logActivity')
        ->assertActionHasLabel('logActivity', 'Log Activity')
        ->mountAction('logActivity')
        ->assertActionDataSet(fn (array $state): bool => $state['deal'] === 'Retainer'
            && $state['contact_name'] === 'Jane Doe'
            && $state['deal_id'] === $this->deal->id);

    expect(LogDealActivityAction::make())->toBeInstanceOf(Action::class)
        ->and(LogDealActivityAction::make()->getName())->toBe('logActivity')
        ->and(LogDealActivityAction::make('other')->getName())->toBe('other');
});

test('logging from the deal page is unchanged', function () {
    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => '2026-10-09 10:00:00',
            'subject' => 'Deal follow-up',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Activity logged')
        ->assertDispatched('activityLogged');

    $activity = Activity::query()->where('subject', 'Deal follow-up')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->contact_id)->toBe($this->contact->id)
        ->and($activity->deal_id)->toBe($this->deal->id)
        ->and($activity->user_id)->toBe($this->user->id);
});

test('a deal of another organization or of another contact is refused as the activity deal', function () {
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Deal::factory()->create(['organization_id' => $otherOrg->id, 'title' => 'Foreign']);
    $stranger = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'title' => 'Stranger',
    ]);

    foreach ([$foreign, $stranger] as $deal) {
        Livewire::test(ViewDeal::class, ['record' => $this->deal->id])
            ->callAction('logActivity', data: [
                'type' => ActivityType::Call,
                'occurred_at' => '2026-10-09 10:00:00',
                'deal_id' => $deal->id,
            ])
            ->assertHasActionErrors(['deal_id']);
    }

    expect(Activity::query()->count())->toBe(0);
});

test('it is hidden without a contact', function () {
    $solo = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'created_by' => $this->user->id,
        'title' => 'Solo',
    ]);

    Livewire::test(ViewDeal::class, ['record' => $solo->id])->assertActionHidden('logActivity');
});

test('a viewer does not see it', function () {
    $viewer = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
    $this->actingAs($viewer);
    Filament::setTenant($this->org);

    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])->assertActionHidden('logActivity');
});

test('the inline new deal belongs to the page deal contact and organization', function () {
    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])
        ->callAction(
            [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
            data: ['title' => 'Inline deal', 'stage' => DealStage::Lead],
        )
        ->assertHasNoActionErrors();

    $inline = Deal::query()->where('title', 'Inline deal')->first();

    expect($inline)->not->toBeNull()
        ->and($inline->contact_id)->toBe($this->contact->id)
        ->and($inline->organization_id)->toBe($this->org->id)
        ->and($inline->created_by)->toBe($this->user->id);
});
