<?php

use App\Enums\DealStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;

test('it consulting seeder seeds core crm modules data', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    expect($organization)->not->toBeNull();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect(Tag::query()->where('organization_id', $organization->id)->count())->toBe(10)
        ->and(CustomField::query()->where('organization_id', $organization->id)->count())->toBe(10)
        ->and(Contact::query()->where('organization_id', $organization->id)->count())->toBe(15)
        ->and(Activity::query()->where('organization_id', $organization->id)->count())->toBe(20)
        ->and(Deal::query()->count())->toBe(6)
        ->and(Task::query()->count())->toBe(6);

    expect(Deal::query()->where('status', DealStatus::Open)->count())->toBe(4)
        ->and(Deal::query()->where('status', DealStatus::Won)->count())->toBe(1)
        ->and(Deal::query()->where('status', DealStatus::Lost)->count())->toBe(1)
        ->and(Task::query()->where('status', TaskStatus::Done)->count())->toBe(1)
        ->and(Task::query()->where('type', TaskType::Invoice)->count())->toBe(1);
});

test('it consulting seeder is idempotent for deals and tasks', function () {
    $this->seed(ItConsultingSeeder::class);
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect(Deal::query()->count())->toBe(6)
        ->and(Task::query()->count())->toBe(6);
});
