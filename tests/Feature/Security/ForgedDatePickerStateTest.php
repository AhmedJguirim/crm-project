<?php

use App\Enums\DealStage;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Support\StateCasts\ScalarDateTimeStateCast;
use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;
use Livewire\Livewire;

/**
 * @return ScalarDateTimeStateCast the cast a date picker gets from the container
 */
function forgedDatePickerCast(): DateTimeStateCast
{
    return app(DateTimeStateCast::class, ['format' => 'Y-m-d', 'internalFormat' => 'Y-m-d', 'timezone' => 'UTC']);
}

test('the container gives date pickers the forged-state-safe cast', function () {
    expect(forgedDatePickerCast())->toBeInstanceOf(ScalarDateTimeStateCast::class);
});

test('a state that is neither a scalar nor a date counts as empty', function (mixed $state) {
    $cast = forgedDatePickerCast();

    expect($cast->set($state))->toBeNull()
        ->and($cast->get($state))->toBeNull();
})->with([
    'list' => [['abc']],
    'map' => [['a' => '1']],
    'nested list' => [[['x']]],
    'object' => [fn () => new stdClass],
]);

test('a real date is unchanged', function () {
    $cast = forgedDatePickerCast();

    expect($cast->set('2026-03-01'))->toBe('2026-03-01')
        ->and($cast->get('2026-03-01'))->toBe('2026-03-01')
        ->and($cast->get(CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC')))->toBe('2026-03-01')
        ->and($cast->set('abc'))->toBeNull()
        ->and($cast->get(null))->toBeNull();
});

test('a forged array in a form date field is still a validation error', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $page = Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Retainer', 'stage' => DealStage::Lead])
        ->set('data.expected_close_date', ['x'])
        ->call('create');

    expect($page->errors()->get('data.expected_close_date'))->toContain('The expected Close Date field must be a valid date.')
        ->and(Deal::query()->where('title', 'Retainer')->exists())->toBeFalse();
});
