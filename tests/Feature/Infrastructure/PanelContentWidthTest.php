<?php

use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

/**
 * @return array<int, class-string<Filament\Pages\Page>>
 */
function panelResourcePageClasses(): array
{
    $classes = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $classes[] = $registration->getPage();
        }
    }

    return array_values(array_unique($classes));
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('the admin panel uses the full window width', function () {
    expect(Filament::getPanel('admin')->getMaxContentWidth())->toBe(Width::Full);
});

test('every create and edit page of the admin panel keeps the 7xl width', function () {
    $checked = [];
    $wrong = [];

    foreach (panelResourcePageClasses() as $class) {
        if (! is_subclass_of($class, CreateRecord::class) && ! is_subclass_of($class, EditRecord::class)) {
            continue;
        }

        $checked[] = $class;

        if ((new $class)->getMaxContentWidth() !== Width::SevenExtraLarge) {
            $wrong[] = $class;
        }
    }

    expect($wrong)->toBe([])
        ->and(count($checked))->toBeGreaterThanOrEqual(16);
});

test('every other resource page inherits the full width', function () {
    $checked = 0;
    $wrong = [];

    foreach (panelResourcePageClasses() as $class) {
        if (is_subclass_of($class, CreateRecord::class) || is_subclass_of($class, EditRecord::class) || $class === SegmentRuleEngine::class) {
            continue;
        }

        $checked++;

        if ((new $class)->getMaxContentWidth() !== null) {
            $wrong[] = $class;
        }
    }

    expect($wrong)->toBe([])
        ->and($checked)->toBeGreaterThan(0);
});

test('non-resource pages have the documented width', function (string $page, ?Width $width) {
    expect((new $page)->getMaxContentWidth())->toBe($width);
})->with([
    'EditOrganization' => [EditOrganization::class, Width::SevenExtraLarge],
    'SegmentRuleEngine' => [SegmentRuleEngine::class, Width::SevenExtraLarge],
    'OrganizationMembers' => [OrganizationMembers::class, null],
]);

test('a list page renders at full width', function () {
    $this->get(ContactResource::getUrl('index'))
        ->assertOk()
        ->assertSee('class="fi-main fi-width-full"', false);
});

test('the deals board renders at full width', function () {
    $this->get(DealResource::getUrl('pipeline'))
        ->assertOk()
        ->assertSee('class="fi-main fi-width-full"', false);
});

test('an edit page renders at 7xl', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->get(ContactResource::getUrl('edit', ['record' => $contact]))
        ->assertOk()
        ->assertSee('class="fi-main fi-width-7xl"', false)
        ->assertDontSee('fi-width-full', false);
});
