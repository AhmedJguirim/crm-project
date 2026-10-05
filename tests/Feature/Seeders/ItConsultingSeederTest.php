<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\SegmentRefreshFrequency;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

function customFieldKey(Model $record, string $name): string
{
    return $record->customFieldDefinitions()->firstWhere('name', $name)->key;
}

/**
 * Whether the contact stores exactly the options with the given labels, in that order.
 *
 * @param  array<int, string>  $labels
 */
function seededOptionLabels(Contact $contact, string $fieldName, array $labels): bool
{
    $field = $contact->customFieldDefinitions()->firstWhere('name', $fieldName);
    $stored = $contact->customFieldValue($field->key);

    return array_map(fn (string $value): string => $field->optionLabels()[$value], $stored) === $labels;
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
        ->and(seededOptionLabels($marcus, 'Tech Stack', ['PHP / Laravel', 'AWS', 'Docker / Kubernetes']))->toBe(true);

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

    $inTenant = fn (array $values): array => app(TenantContext::class)->run($type->organization_id, fn (): array => $keyCompanyValues($type, $values));

    expect(fn () => $inTenant(['industry' => 'IT', 'headcount' => 10]))
        ->toThrow(RuntimeException::class, 'The seeder has a value for [headcount], but company type [Client] has no custom field with that name.')
        ->and(array_values($inTenant(['industry' => 'IT'])))->toBe(['IT']);
});

test('it consulting seeder seeds synced segments for every kind of condition and a draft', function () {
    $this->travelTo(now()->setDate(2026, 10, 3));

    $this->seed(ItConsultingSeeder::class);
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $members = Segment::query()->orderBy('id')->get()->mapWithKeys(fn (Segment $segment): array => [
        $segment->name => $segment->contacts()->orderBy('name')->pluck('name')->all(),
    ]);

    expect($members->map(fn (array $names): int => count($names))->all())->toBe([
        'VIP' => 3,
        'Laravel developers' => 3,
        'Open deals' => 4,
        'Won customers' => 1,
        'Agency contacts' => 4,
        'Never contacted' => 2,
        'Gone quiet (hourly)' => 13,
        'Added recently (daily)' => 15,
        'High rate (draft)' => 0,
    ])
        ->and($members['VIP'])->toBe(['Marcus Chen', 'Priya Nair', 'Yasmin Al-Rashid'])
        ->and($members['Won customers'])->toBe(['Priya Nair'])
        ->and($members['Never contacted'])->toBe(['Florian Dupont', 'Hugo Fernandes'])
        ->and(Segment::query()->where('name', 'Gone quiet (hourly)')->sole()->refreshFrequency())->toBe(SegmentRefreshFrequency::Hourly)
        ->and(Segment::query()->where('name', 'Added recently (daily)')->sole()->refreshFrequency())->toBe(SegmentRefreshFrequency::Daily)
        ->and(Segment::query()->where('name', 'High rate (draft)')->sole())
        ->is_published->toBeFalse()
        ->hasPendingChanges()->toBeTrue();
});

test('it consulting seeder seeds a second organization with lookalike data for cross-organization checks', function () {
    $this->seed(ItConsultingSeeder::class);
    $this->seed(ItConsultingSeeder::class);

    $testUser = User::query()->where('email', 'test@example.com')->firstOrFail();
    $owner = User::query()->where('email', 'other@example.com')->firstOrFail();
    $globex = Organization::query()->where('slug', 'globex-demo')->sole();

    expect($globex->members()->pluck('role', 'email')->all())->toEqualCanonicalizing([
        'other@example.com' => OrganizationRole::Owner->value,
        'test@example.com' => OrganizationRole::Member->value,
    ])
        ->and($owner->organizations()->pluck('slug')->all())->toBe(['globex-demo'])
        ->and($testUser->organizations()->count())->toBe(2);

    $this->actingAs($testUser);
    Filament::setTenant($globex);

    $vip = Segment::query()->where('name', 'VIP')->sole();

    expect(Contact::query()->orderBy('name')->pluck('name')->all())->toBe(['Globex Alice', 'Globex Bob', 'Globex Carol'])
        ->and(Tag::query()->pluck('name')->all())->toBe(['VIP'])
        ->and(Segment::query()->count())->toBe(1)
        ->and($vip->contacts()->orderBy('name')->pluck('name')->all())->toBe(['Globex Alice', 'Globex Bob']);

    Filament::setTenant($testUser->personalOrganization());

    expect(Contact::query()->count())->toBe(15)
        ->and(Segment::query()->where('name', 'VIP')->sole()->contacts()->pluck('name')->all())->not->toContain('Globex Alice');
});

test('it consulting seeder has one user per role in the demo organization', function () {
    $this->seed(ItConsultingSeeder::class);

    $organization = User::where('email', 'test@example.com')->firstOrFail()->personalOrganization();

    expect($organization->members()->pluck('organization_user.role', 'email')->all())->toBe([
        'test@example.com' => 'owner',
        'admin@example.com' => 'admin',
        'member@example.com' => 'member',
        'viewer@example.com' => 'viewer',
    ]);

    $this->seed(ItConsultingSeeder::class);

    expect($organization->members()->count())->toBe(4);
});

test('it consulting seeder gives every option a generated value, declared by label only', function () {
    $this->seed(ItConsultingSeeder::class);

    $fields = [...CustomField::withoutGlobalScopes()->whereIn('type', ['select', 'multiselect'])->get(), ...CompanyCustomField::withoutGlobalScopes()->whereIn('type', ['select', 'multiselect'])->get()];
    $values = collect($fields)->flatMap(fn ($field): array => collect($field->options)->pluck('value')->all());

    expect($fields)->not->toBeEmpty()
        ->and($values->every(fn (string $value): bool => preg_match('/^opt_[a-z0-9]{10}$/', $value) === 1))->toBeTrue()
        ->and($values->unique()->count())->toBe($values->count());
});

test('it consulting seeder keeps the meaning of its options', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $stack = CustomField::query()->where('name', 'Tech Stack')->sole();
    $laravel = collect($stack->options)->firstWhere('label', 'PHP / Laravel')['value'];

    expect(Contact::query()->whereJsonContains("custom_field_values->{$stack->key}", $laravel)->orderBy('name')->pluck('name')->all())
        ->toBe(['Lars Müller', 'Marcus Chen', 'Nadia Petrova'])
        ->and(Company::query()->where('name', 'StackOps')->sole()->customFieldValue(CompanyCustomField::query()->where('name', 'Funding Stage')->sole()->key))
        ->toBe(collect(CompanyCustomField::query()->where('name', 'Funding Stage')->sole()->options)->firstWhere('label', 'Series A')['value']);
});

test('it consulting seeder fails loudly on an unknown option label', function () {
    $field = CustomField::factory()->create(['type' => 'select', 'options' => [['label' => 'Signed']]]);
    $optionValues = Closure::bind(fn (CustomField $field, array $labels): array => $this->optionValues($field, $labels), new ItConsultingSeeder, ItConsultingSeeder::class);

    expect($optionValues($field, ['Signed']))->toHaveCount(1)
        ->and(fn () => $optionValues($field, ['Signd']))->toThrow(RuntimeException::class, 'has no option with that label');
});
