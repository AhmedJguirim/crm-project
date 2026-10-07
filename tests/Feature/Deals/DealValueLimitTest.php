<?php

use App\Enums\DealStage;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
});

test('the main deal form refuses a value above the column size', function (string|float|int $value) {
    Livewire::test(CreateDeal::class)
        ->fillForm([
            'title' => 'Big one',
            'stage' => DealStage::Lead,
            'currency' => 'USD',
            'value' => $value,
        ])
        ->call('create')
        ->assertHasFormErrors(['value' => 'max']);

    expect(Deal::query()->count())->toBe(0);
})->with([
    'one hundred million' => [100000000],
    'a trillion' => [999999999999],
    'rounds up to one hundred million' => ['99999999.995'],
]);

test('the main deal form accepts the largest value the column holds', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm([
            'title' => 'Big one',
            'stage' => DealStage::Lead,
            'currency' => 'USD',
            'value' => '99999999.99',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Deal::query()->firstOrFail()->value)->toBe('99999999.99');
});

test('the edit deal form refuses a value above the column size', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'value' => 1000,
    ]);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['value' => 100000000])
        ->call('save')
        ->assertHasFormErrors(['value' => 'max']);

    expect($deal->refresh()->value)->toBe('1000.00');
});

dataset('deal option forms', [
    'view deal log activity' => [
        fn () => Livewire::test(ViewDeal::class, ['record' => Deal::factory()->create([
            'organization_id' => Filament::getTenant()->id,
            'contact_id' => test()->contact->id,
            'created_by' => test()->user->id,
        ])->id]),
        fn (): array => [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'view contact log activity' => [
        fn () => Livewire::test(ViewContact::class, ['record' => test()->contact->id]),
        fn (): array => [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'contacts table log activity' => [
        fn () => Livewire::test(ListContacts::class),
        fn (): array => [TestAction::make('logActivity')->table(test()->contact), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'quick task on a contact' => [
        fn () => Livewire::test(ViewContact::class, ['record' => test()->contact->id])->mountAction('quickTask'),
        fn (): TestAction => TestAction::make('createOption')->schemaComponent('deal_id'),
    ],
]);

test('the create deal option form of each select refuses a value above the column size', function (Closure $page, Closure $actions) {
    $page()
        ->callAction($actions(), data: [
            'title' => 'Big',
            'stage' => DealStage::Lead,
            'currency' => 'USD',
            'value' => 100000000,
        ])
        ->assertHasActionErrors(['value' => 'max']);

    expect(Deal::query()->where('title', 'Big')->exists())->toBeFalse();
})->with('deal option forms');

test('the create deal option form of each select accepts the largest value', function (Closure $page, Closure $actions) {
    $page()
        ->callAction($actions(), data: [
            'title' => 'Big',
            'stage' => DealStage::Lead,
            'currency' => 'USD',
            'value' => '99999999.99',
        ])
        ->assertHasNoActionErrors();

    expect(Deal::query()->where('title', 'Big')->firstOrFail()->value)->toBe('99999999.99');
})->with('deal option forms');

test('the create task form option for a deal refuses a value above the column size', function () {
    Livewire::test(CreateTask::class)
        ->fillForm(['contact_id' => $this->contact->id])
        ->callAction(TestAction::make('createOption')->schemaComponent('deal_id'), data: [
            'title' => 'Big',
            'stage' => DealStage::Lead,
            'currency' => 'USD',
            'value' => 100000000,
        ])
        ->assertHasActionErrors(['value' => 'max']);

    expect(Deal::query()->where('title', 'Big')->exists())->toBeFalse();
});

test('the create task form option for a deal accepts the largest value', function () {
    Livewire::test(CreateTask::class)
        ->fillForm(['contact_id' => $this->contact->id])
        ->callAction(TestAction::make('createOption')->schemaComponent('deal_id'), data: [
            'title' => 'Big',
            'stage' => DealStage::Lead,
            'currency' => 'USD',
            'value' => '99999999.99',
        ])
        ->assertHasNoActionErrors();

    expect(Deal::query()->where('title', 'Big')->firstOrFail()->value)->toBe('99999999.99');
});
