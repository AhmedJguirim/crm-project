<?php

use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('deals list page loads', function () {
    Livewire::test(ListDeals::class)
        ->assertOk();
});

test('deals create page loads', function () {
    Livewire::test(CreateDeal::class)
        ->assertOk();
});

test('deals edit page loads', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->assertOk();
});

test('deals view page loads', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->assertOk();
});
