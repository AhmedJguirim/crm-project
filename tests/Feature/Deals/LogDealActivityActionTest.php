<?php

use App\Enums\ActivityType;
use App\Enums\OrganizationRole;
use App\Filament\Actions\LogDealActivityAction;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Jobs\ResyncContactSegments;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the deal page builds its action from the class', function () {
    $hasPrefill = function (array $state): array {
        expect($state['deal'])->toBe('Retainer')
            ->and($state['contact_name'])->toBe('Jane Doe')
            ->and($state['deal_id'])->toEqual($this->deal->id);

        return [];
    };

    $page = Livewire::test(ViewDeal::class, ['record' => $this->deal->id]);

    $page->assertActionVisible('logActivity')
        ->assertActionHasLabel('logActivity', 'Log Activity')
        ->mountAction('logActivity')
        ->assertActionDataSet($hasPrefill);

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

test('it is visible on a deal without a contact and shows No contact', function () {
    $solo = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'created_by' => $this->user->id,
        'title' => 'Solo',
    ]);

    $hasPrefill = function (array $state) use ($solo): array {
        expect($state['deal'])->toBe('Solo')
            ->and($state['contact_name'])->toBeNull()
            ->and($state['contact_id'])->toBeNull()
            ->and($state['deal_id'])->toEqual($solo->id);

        return [];
    };

    Livewire::test(ViewDeal::class, ['record' => $solo->id])
        ->assertActionVisible('logActivity')
        ->mountAction('logActivity')
        ->assertActionDataSet($hasPrefill)
        ->assertSchemaComponentExists('contact_name', checkComponentUsing: fn (TextInput $field): bool => $field->getPlaceholder() === 'No contact');
});

test('logging on a deal without a contact stores a deal-only activity', function () {
    Queue::fake();

    $solo = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'company_id' => null,
        'created_by' => $this->user->id,
        'title' => 'Solo',
    ]);

    Livewire::test(ViewDeal::class, ['record' => $solo->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => '2026-10-09 10:00:00',
            'subject' => 'Kick-off call',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Activity logged')
        ->assertDispatched('activityLogged');

    $activity = Activity::query()->where('subject', 'Kick-off call')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->contact_id)->toBeNull()
        ->and($activity->deal_id)->toBe($solo->id)
        ->and($activity->company_id)->toBeNull()
        ->and($activity->user_id)->toBe($this->user->id);

    Queue::assertNotPushed(ResyncContactSegments::class);
});

test('another deal of the same contact can be chosen as the activity deal', function () {
    $sibling = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'title' => 'Sibling',
    ]);

    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => '2026-10-09 10:00:00',
            'subject' => 'Sibling call',
            'deal_id' => $sibling->id,
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->where('subject', 'Sibling call')->first();

    expect($activity->deal_id)->toBe($sibling->id)
        ->and($activity->contact_id)->toBe($this->contact->id);
});

test('on a deal without a contact only that deal can be chosen', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);
    $otherSolo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Other solo']);

    Livewire::test(ViewDeal::class, ['record' => $solo->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => '2026-10-09 10:00:00',
            'deal_id' => $otherSolo->id,
        ])
        ->assertHasActionErrors(['deal_id']);

    expect(Activity::query()->count())->toBe(0);
});

test('only a deal with a contact has an inline create deal', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);
    $chain = [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')];

    Livewire::test(ViewDeal::class, ['record' => $solo->id])->assertActionDoesNotExist($chain);
    Livewire::test(ViewDeal::class, ['record' => $this->deal->id])->assertActionExists($chain);
});

test('a deal whose contact is soft-deleted logs a deal-only activity', function () {
    $oldClient = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Old Client']);
    $ghost = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $oldClient->id,
        'created_by' => $this->user->id,
        'title' => 'Ghost',
    ]);
    $oldClient->delete();

    $hasGhostPrefill = function (array $state) use ($ghost): array {
        expect($state['contact_name'])->toBeNull()
            ->and($state['contact_id'])->toBeNull()
            ->and($state['deal_id'])->toEqual($ghost->id);

        return [];
    };

    Livewire::test(ViewDeal::class, ['record' => $ghost->id])
        ->assertActionVisible('logActivity')
        ->mountAction('logActivity')
        ->assertActionDataSet($hasGhostPrefill)
        ->unmountAction()
        ->callAction('logActivity', data: [
            'type' => ActivityType::Note,
            'occurred_at' => '2026-10-09 10:00:00',
            'subject' => 'Archived note',
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->where('subject', 'Archived note')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->contact_id)->toBeNull()
        ->and($activity->deal_id)->toBe($ghost->id);
});

test('a viewer does not see it on a deal without a contact', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);
    $viewer = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
    $this->actingAs($viewer);
    Filament::setTenant($this->org);

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
            data: ['title' => 'Inline deal'],
        )
        ->assertHasNoActionErrors();

    $inline = Deal::query()->where('title', 'Inline deal')->first();

    expect($inline)->not->toBeNull()
        ->and($inline->contact_id)->toBe($this->contact->id)
        ->and($inline->organization_id)->toBe($this->org->id)
        ->and($inline->created_by)->toBe($this->user->id);
});
