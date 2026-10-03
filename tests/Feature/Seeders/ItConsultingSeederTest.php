<?php

use App\Enums\DealStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

function customFieldKey(Model $record, string $name): string
{
    return $record->customFieldDefinitions()->firstWhere('name', $name)->key;
}

test('it consulting seeder seeds core crm modules data', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    expect($organization)->not->toBeNull();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect(Tag::query()->where('organization_id', $organization->id)->count())->toBe(10)
        ->and(CustomField::query()->where('organization_id', $organization->id)->count())->toBe(9)
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

test('it consulting seeder seeds companies with types, custom fields, addresses and contacts', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect(CompanyType::query()->pluck('name')->sort()->values()->all())->toBe(['Agency', 'Enterprise', 'SME', 'Startup'])
        ->and(Company::query()->count())->toBe(14)
        ->and(CompanyCustomField::query()->count())->toBe(13)
        ->and(Company::query()->whereNull('address_id')->count())->toBe(0);

    $techCorp = Company::query()->where('name', 'TechCorp Solutions')->firstOrFail();

    expect($techCorp->companyType->name)->toBe('Enterprise')
        ->and($techCorp->address->city)->toBe('Paris')
        ->and($techCorp->customFieldDefinitions()->pluck('name')->all())
        ->toBe(['Industry', 'Employees', 'Website', 'VAT Number', 'Annual Revenue (€)'])
        ->and($techCorp->customFieldValue(customFieldKey($techCorp, 'VAT Number')))->toBe('FR40303265045')
        ->and($techCorp->contacts->pluck('email')->all())->toBe(['marcus.chen@techcorp.io']);

    $marcus = Contact::query()->where('email', 'marcus.chen@techcorp.io')->firstOrFail();

    expect($marcus->customFieldValue(customFieldKey($marcus, 'Job Title')))->toBe('CTO')
        ->and($marcus->customFieldValue(customFieldKey($marcus, 'Tech Stack')))->toBe(['laravel', 'aws', 'docker']);

    $florian = Contact::query()->where('email', 'florian.dupont@freelance.io')->firstOrFail();

    expect($florian->companies->pluck('name')->sort()->values()->all())->toBe(['PixelCraft Studio', 'StackOps']);
});

test('it consulting seeder is idempotent for companies', function () {
    $this->seed(ItConsultingSeeder::class);
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    expect(Company::query()->count())->toBe(14)
        ->and(CompanyType::query()->count())->toBe(4)
        ->and(CompanyCustomField::query()->count())->toBe(13)
        ->and(Contact::query()->withCount('companies')->get()->sum('companies_count'))->toBe(16);
});

test('it consulting seeder names the company type and the field when a value has no custom field', function () {
    $type = CompanyType::factory()->create(['name' => 'Client']);
    CompanyCustomField::factory()->create(['organization_id' => $type->organization_id, 'company_type_id' => $type->id, 'name' => 'Industry', 'type' => 'text']);
    CompanyCustomField::factory()->create(['organization_id' => $type->organization_id, 'company_type_id' => $type->id, 'name' => 'Size', 'type' => 'text']);

    $keyCompanyValues = Closure::bind(
        fn (CompanyType $type, array $values): array => $this->keyCompanyValues($type, $values),
        new ItConsultingSeeder,
        ItConsultingSeeder::class,
    );

    expect(fn () => $keyCompanyValues($type, ['industry' => 'IT', 'headcount' => 10]))
        ->toThrow(RuntimeException::class, 'The seeder has a value for [headcount], but company type [Client] has no custom field with that name.')
        ->and(array_values($keyCompanyValues($type, ['industry' => 'IT'])))->toBe(['IT']);
});
