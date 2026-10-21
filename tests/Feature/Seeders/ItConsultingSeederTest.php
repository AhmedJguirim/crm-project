<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\CompanyIndustry;
use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\ReportPeriodPreset;
use App\Enums\SegmentRefreshFrequency;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Filament\Widgets\MoneyOwedWidget;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\LostReason;
use App\Models\Organization;
use App\Models\PipelineStage;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use App\Services\Reports\DashboardKpis;
use App\Services\Reports\TeamActivity;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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
        ->and(Activity::query()->where('organization_id', $organization->id)->count())->toBe(44)
        ->and(Deal::query()->count())->toBe(18)
        ->and(Task::query()->count())->toBe(18);

    expect(Deal::query()->where('status', DealStatus::Open)->count())->toBe(4)
        ->and(Deal::query()->where('status', DealStatus::Won)->count())->toBe(8)
        ->and(Deal::query()->where('status', DealStatus::Lost)->count())->toBe(6)
        ->and(Task::query()->where('status', TaskStatus::Done)->count())->toBe(13)
        ->and(Task::query()->where('type', TaskType::Invoice)->count())->toBe(2);
});

test('it consulting seeder is idempotent for deals and tasks', function () {
    $this->seed(ItConsultingSeeder::class);
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect(Deal::query()->count())->toBe(18)
        ->and(DealStageChange::query()->count())->toBe(54)
        ->and(Task::query()->count())->toBe(18)
        ->and(Activity::query()->count())->toBe(44);
});

test('it consulting seeder seeds companies with types, custom fields, addresses and contacts', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect(CompanyType::query()->pluck('name')->sort()->values()->all())->toBe(['Agency', 'Enterprise', 'SME', 'Startup'])
        ->and(Company::query()->count())->toBe(14)
        ->and(CompanyCustomField::query()->count())->toBe(4)
        ->and(Company::query()->whereNull('address_id')->count())->toBe(0);

    $techCorp = Company::query()->where('name', 'TechCorp Solutions')->firstOrFail();

    expect($techCorp->companyType->name)->toBe('Enterprise')
        ->and($techCorp->address->city)->toBe('Paris')
        ->and($techCorp->customFieldDefinitions()->pluck('name')->all())
        ->toBe(['VAT Number', 'Funding Stage', 'Founded', 'Specialties'])
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
        ->and(LostReason::query()->count())->toBe(5)
        ->and(LostReason::query()->pluck('name')->sort()->values()->all())->toBe(['Bad timing', 'No budget', 'No response', 'Price too high', 'Went with a competitor'])
        ->and(Deal::query()->where('title', 'NordicSec Pen Testing Extension')->firstOrFail()->lostReason?->name)->toBe('No budget')
        ->and(Deal::query()->where('title', 'NordicSec Pen Testing Extension')->firstOrFail()->lost_reason_note)->toBe('Budget reallocated to internal team.')
        ->and(CompanyCustomField::query()->count())->toBe(4)
        ->and(Contact::query()->withCount('companies')->get()->sum('companies_count'))->toBe(16);
});

test('it consulting seeder names the missing company field', function () {
    $organization = Organization::factory()->create();
    CompanyCustomField::factory()->create(['organization_id' => $organization->id, 'name' => 'Industry', 'type' => 'text']);
    CompanyCustomField::factory()->create(['organization_id' => $organization->id, 'name' => 'Size', 'type' => 'text']);

    $keyCompanyValues = Closure::bind(
        fn (Organization $organization, array $values): array => $this->keyCompanyValues($organization, $values),
        new ItConsultingSeeder,
        ItConsultingSeeder::class,
    );

    $inTenant = fn (array $values): array => app(TenantContext::class)->run($organization->id, fn (): array => $keyCompanyValues($organization, $values));

    expect(fn () => $inTenant(['industry' => 'IT', 'headcount' => 10]))
        ->toThrow(RuntimeException::class, 'The seeder has a value for [headcount], but the organization has no company field with that name.')
        ->and(array_values($inTenant(['industry' => 'IT'])))->toBe(['IT']);
});

test('it consulting seeder gives the companies a website, its domain and a phone', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $techCorp = Company::query()->where('name', 'TechCorp Solutions')->firstOrFail();
    $hexaSys = Company::query()->where('name', 'HexaSys')->firstOrFail();

    expect(CompanyCustomField::query()->where('name', 'Website')->exists())->toBeFalse()
        ->and($techCorp->only(['website', 'domain', 'phone']))->toBe(['website' => 'https://techcorp.io', 'domain' => 'techcorp.io', 'phone' => '+33 1 23 45 67 89'])
        ->and(Company::query()->whereNotNull('phone')->orderBy('name')->pluck('phone', 'name')->all())
        ->toBe(['Cloudbase Nordic' => '+46 8 123 456 78', 'StackOps' => '+353 1 234 5678', 'TechCorp Solutions' => '+33 1 23 45 67 89'])
        ->and(Company::query()->whereNotNull('domain')->orderBy('domain')->pluck('domain')->all())
        ->toBe(['cloudbase.dev', 'devstudio.de', 'pixelcraft.studio', 'securepeak.com', 'stackops.io', 'techcorp.io'])
        ->and($hexaSys->only(['website', 'domain']))->toBe(['website' => null, 'domain' => null]);
});

test('it consulting seeder keeps the industries in their column', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $industry = fn (string $name): ?CompanyIndustry => Company::query()->where('name', $name)->firstOrFail()->industry;

    expect($industry('TechCorp Solutions'))->toBe(CompanyIndustry::Software)
        ->and($industry('InnoTech SA'))->toBe(CompanyIndustry::FinanceBanking)
        ->and($industry('Cloudbase Nordic'))->toBe(CompanyIndustry::CloudHosting)
        ->and($industry('StackOps'))->toBeNull()
        ->and(Company::query()->whereNotNull('industry')->count())->toBe(8)
        ->and(CompanyCustomField::query()->where('name', 'Industry')->exists())->toBeFalse();
});

test('it consulting seeder keeps the sizes of the companies in their columns', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $company = fn (string $name): Company => Company::query()->where('name', $name)->firstOrFail();

    expect($company('TechCorp Solutions')->only(['employees', 'annual_revenue']))->toBe(['employees' => 1200, 'annual_revenue' => '180000000.00'])
        ->and($company('Cloudbase Nordic')->only(['employees', 'annual_revenue']))->toBe(['employees' => 85, 'annual_revenue' => null])
        ->and($company('StackOps')->employees)->toBeNull()
        ->and(Company::query()->whereNotNull('employees')->pluck('employees', 'name')->sortKeys()->all())->toBe([
            'CloudShift MENA' => 700, 'Cloudbase Nordic' => 85, 'HexaSys' => 140, 'InnoTech SA' => 2300,
            'Nordic Security AB' => 45, 'SecurePeak Ltd' => 950, 'SoftBridge Europe' => 60, 'TechCorp Solutions' => 1200,
        ])
        ->and(Company::query()->whereNotNull('annual_revenue')->count())->toBe(1)
        ->and(CompanyCustomField::query()->whereIn('name', ['Employees', 'Annual Revenue (€)'])->exists())->toBeFalse();
});

test('it consulting seeder keeps the company values it had, now on organization-wide fields', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $valueOf = function (string $company, string $fieldName): mixed {
        $company = Company::query()->where('name', $company)->firstOrFail();
        $field = $company->customFieldDefinitions()->firstWhere('name', $fieldName);
        $stored = $company->customFieldValue($field->key);

        return match ($field->type) {
            'select' => $field->optionLabels()[$stored] ?? null,
            'multiselect' => array_map(fn (string $value): string => $field->optionLabels()[$value], $stored ?? []),
            default => $stored,
        };
    };

    expect($valueOf('TechCorp Solutions', 'VAT Number'))->toBe('FR40303265045')
        ->and($valueOf('StackOps', 'Funding Stage'))->toBe('Series A')
        ->and($valueOf('StackOps', 'Founded'))->toBe('2021-03-01')
        ->and($valueOf('DevStudio Berlin', 'Specialties'))->toBe(['Web', 'Design'])
        ->and(CompanyCustomField::query()->orderBy('order')->pluck('name')->all())
        ->toBe(['VAT Number', 'Funding Stage', 'Founded', 'Specialties']);
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
        'Won customers' => 7,
        'Agency contacts' => 4,
        'Never contacted' => 2,
        'Gone quiet (hourly)' => 5,
        'Added recently (daily)' => 15,
        'High rate (draft)' => 0,
    ])
        ->and($members['VIP'])->toBe(['Marcus Chen', 'Priya Nair', 'Yasmin Al-Rashid'])
        ->and(collect($members['Won customers'])->sort(SORT_STRING)->values()->all())->toBe(['Elena Vasquez', 'Lars Müller', 'Marcus Chen', 'Nadia Petrova', 'Priya Nair', 'Sarah Johansson', 'Tom Whitfield'])
        ->and(Segment::query()->where('name', 'Won customers')->sole()->workingRules()->sole()->conditions[0]->value)
        ->toBe(['deal_stages' => [DefaultPipeline::stageOfType($user->personalOrganization()->id, DealStatus::Won)->id]])
        ->and($members['Never contacted'])->toBe(['Florian Dupont', 'Hugo Fernandes'])
        ->and($members['Gone quiet (hourly)'])->toBe(['Amara Diallo', 'Ingrid Svensson', 'James Okafor', 'Nadia Petrova', 'Remy Bertrand'])
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
        ->and(LostReason::query()->forOrganization($globex->id)->count())->toBe(0)
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

test('it consulting seeder seeds one currency and the dashboard adds the amounts up in it', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $organization = $user->personalOrganization();

    $this->actingAs($user);
    Filament::setTenant($organization);

    expect($organization->currency)->toBe(Currency::Usd)
        ->and(Invoice::query()->whereIn('invoice_number', ['INV-2026-001', 'INV-2026-002', 'INV-2026-003', 'INV-2026-004', 'INV-2026-005', 'INV-2026-006'])->pluck('created_by')->unique()->all())->toBe([$user->id]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Pipeline Value', '$222,000.00']);

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['Outstanding', '$50,500.00', '6 unpaid invoices · $42,700.00 overdue'])
        ->assertSeeInOrder(['Not due yet', '$7,800.00', '1 invoice'])
        ->assertSeeInOrder(['1–30 days late', '$12,800.00', '2 invoices'])
        ->assertSeeInOrder(['31–60 days late', '$23,000.00', '1 invoice'])
        ->assertSeeInOrder(['61–90 days late', '$2,400.00', '1 invoice'])
        ->assertSeeInOrder(['Over 90 days late', '$4,500.00', '1 invoice']);

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSeeInOrder(['INV-2026-010', 'INV-2026-012', 'INV-2026-013', 'INV-2026-004', 'INV-2026-003']);

    expect(Invoice::query()->where('invoice_number', 'INV-2026-003')->value('amount_paid'))->toBe('2000.00')
        ->and(Invoice::query()->where('invoice_number', 'INV-2026-001')->value('amount_paid'))->toBe('24000.00')
        ->and(Invoice::query()->where('invoice_number', 'INV-2026-005')->value('amount_paid'))->toBe('31000.00');
});

test('it consulting seeder records the seeded payments once in the ledger', function () {
    $this->seed(ItConsultingSeeder::class);
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    foreach (['INV-2026-001' => '24000.00', 'INV-2026-003' => '2000.00', 'INV-2026-005' => '31000.00'] as $number => $amount) {
        $invoice = Invoice::query()->where('invoice_number', $number)->firstOrFail();
        $entries = $invoice->payments()->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->kind)->toBe(InvoicePaymentKind::Payment)
            ->and($entries->first()->amount)->toBe($amount)
            ->and($entries->first()->paid_on->toDateString())->toBe($invoice->paid_at->toDateString())
            ->and($entries->first()->method)->toBe(PaymentMethod::BankTransfer);
    }

    expect(InvoicePayment::query()->count())->toBe(12);
});

test('it consulting seeder gives the demo deals to test@example.com except one', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $owners = Deal::query()->pluck('owner_id', 'title')->only([
        'TechCorp Infra Expansion',
        'Cloudbase Cost Optimization Retainer',
        'HexaSys React Platform Migration',
        'SecurePeak Annual Security Program',
        'InnoTech Transformation Advisory',
        'NordicSec Pen Testing Extension',
    ]);

    expect($owners['InnoTech Transformation Advisory'])->toBeNull()
        ->and($owners->except('InnoTech Transformation Advisory')->unique()->values()->all())->toBe([$user->id]);
});

test('it consulting seeder gives every demo deal the single company of its contact', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $deal = Deal::query()->where('title', 'TechCorp Infra Expansion')->firstOrFail();

    expect(Deal::query()->whereNull('company_id')->count())->toBe(0)
        ->and($deal->company->name)->toBe('TechCorp Solutions');
});

test('it consulting seeder assigns the demo tasks to test@example.com except one', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $originals = ['Finalize TechCorp contract redlines', 'Call Sarah for proposal approval', 'Prepare HexaSys discovery workshop agenda', 'Send SecurePeak kickoff summary', 'Invoice StackOps sprint support', 'Follow up with Yasmin on budget confirmation'];
    $owners = Task::query()->whereIn('title', $originals)->pluck('owner_id', 'title');

    expect($owners['Invoice StackOps sprint support'])->toBeNull()
        ->and($owners->except('Invoice StackOps sprint support')->unique()->values()->all())->toBe([$user->id]);
});

test('it consulting seeder assigns the demo contacts and companies to test@example.com except two', function () {
    $this->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    $contactOwners = Contact::query()->pluck('owner_id', 'email');
    $companyOwners = Company::query()->pluck('owner_id', 'name');

    expect($contactOwners['amara.diallo@innotech-sa.com'])->toBeNull()
        ->and($contactOwners->except('amara.diallo@innotech-sa.com')->unique()->values()->all())->toBe([$user->id])
        ->and($companyOwners['InnoTech SA'])->toBeNull()
        ->and($companyOwners->except('InnoTech SA')->unique()->values()->all())->toBe([$user->id]);
});

test('it consulting seeder gives every deal a pipeline stage of its organization named like its stage', function () {
    $this->seed(ItConsultingSeeder::class);

    $deals = Deal::query()->withoutGlobalScope('organization')->get();

    expect($deals)->not->toBeEmpty();

    $expectedStages = [
        'TechCorp Infra Expansion' => 'Negotiating',
        'Cloudbase Cost Optimization Retainer' => 'Proposal Sent',
        'HexaSys React Platform Migration' => 'Discovery',
        'SecurePeak Annual Security Program' => 'Won',
        'InnoTech Transformation Advisory' => 'Lead',
        'NordicSec Pen Testing Extension' => 'Lost',
    ];

    foreach ($deals as $deal) {
        $stage = PipelineStage::query()->withoutGlobalScope('organization')->findOrFail($deal->pipeline_stage_id);

        expect($stage->organization_id)->toBe($deal->organization_id);
    }

    foreach ($expectedStages as $title => $name) {
        expect(PipelineStage::query()->withoutGlobalScope('organization')->findOrFail($deals->firstWhere('title', $title)->pipeline_stage_id)->name)->toBe($name);
    }

    $stageNameOf = fn (string $title): string => PipelineStage::query()->withoutGlobalScope('organization')
        ->findOrFail($deals->firstWhere('title', $title)->pipeline_stage_id)->name;

    expect($stageNameOf('TechCorp Infra Expansion'))->toBe('Negotiating')
        ->and($stageNameOf('SecurePeak Annual Security Program'))->toBe('Won');
});

/**
 * Seeds the demo data with the clock at 2026-10-21 12:00 UTC and returns the demo organization.
 */
function seedHistoryAt(object $test): Organization
{
    $test->travelTo(Carbon::parse('2026-10-21 12:00:00', 'UTC'));
    $test->seed(ItConsultingSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $test->actingAs($user);
    Filament::setTenant($user->personalOrganization());

    return $user->personalOrganization();
}

/**
 * The stage history of a seeded deal as "from > to @ changed_at" lines, oldest first.
 *
 * @return array<int, string>
 */
function seededHistoryOf(string $title): array
{
    return Deal::query()->where('title', $title)->firstOrFail()->stageChanges()
        ->with(['fromStage', 'toStage'])->orderBy('changed_at')->orderBy('id')->get()
        ->map(fn (DealStageChange $change): string => ($change->fromStage?->name ?? '(none)').' > '.$change->toStage->name.' @ '.$change->changed_at->toDateTimeString())
        ->all();
}

test('it consulting seeder seeds closed deals whose totals and months are stable', function () {
    seedHistoryAt($this);

    $sum = fn (DealStatus $status): string => number_format((float) Deal::query()->where('status', $status)->sum('value'), 2, '.', '');
    $original = ['TechCorp Infra Expansion', 'Cloudbase Cost Optimization Retainer', 'HexaSys React Platform Migration', 'SecurePeak Annual Security Program', 'InnoTech Transformation Advisory', 'NordicSec Pen Testing Extension'];
    $october = Deal::query()->whereNotIn('title', $original)
        ->where(fn ($query) => $query->whereBetween('created_at', ['2026-10-01', '2026-11-01'])
            ->orWhereBetween('won_at', ['2026-10-01', '2026-11-01'])
            ->orWhereBetween('lost_at', ['2026-10-01', '2026-11-01']))
        ->count();

    expect(Deal::query()->count())->toBe(18)
        ->and(Deal::query()->where('status', DealStatus::Open)->count())->toBe(4)
        ->and(Deal::query()->where('status', DealStatus::Won)->count())->toBe(8)
        ->and(Deal::query()->where('status', DealStatus::Lost)->count())->toBe(6)
        ->and($sum(DealStatus::Won))->toBe('332500.00')
        ->and($sum(DealStatus::Lost))->toBe('216000.00')
        ->and(DealStageChange::query()->count())->toBe(54)
        ->and($october)->toBe(0);
});

test('it consulting seeder moves a won deal through every stage at its own dates', function () {
    seedHistoryAt($this);

    $deal = Deal::query()->with(['contact', 'company'])->where('title', 'TechCorp Security Audit')->firstOrFail();
    $testId = User::query()->where('email', 'test@example.com')->value('id');

    expect($deal->owner_id)->toBe($testId)
        ->and($deal->created_by)->toBe($testId)
        ->and($deal->contact->name)->toBe('Marcus Chen')
        ->and($deal->company->name)->toBe('TechCorp Solutions')
        ->and($deal->value)->toBe('48000.00')
        ->and($deal->created_at->toDateTimeString())->toBe('2026-04-04 12:00:00')
        ->and($deal->won_at->toDateTimeString())->toBe('2026-05-19 12:00:00')
        ->and($deal->lost_at)->toBeNull()
        ->and($deal->expected_close_date->toDateString())->toBe('2026-05-19')
        ->and(seededHistoryOf('TechCorp Security Audit'))->toBe([
            '(none) > Lead @ 2026-04-04 12:00:00',
            'Lead > Discovery @ 2026-04-12 12:00:00',
            'Discovery > Proposal Sent @ 2026-04-25 12:00:00',
            'Proposal Sent > Negotiating @ 2026-05-08 12:00:00',
            'Negotiating > Won @ 2026-05-19 12:00:00',
        ])
        ->and($deal->stageChanges()->whereNotNull('changed_by')->count())->toBe(0);
});

test('it consulting seeder seeds a path that skips a stage', function () {
    seedHistoryAt($this);

    $deal = Deal::query()->where('title', 'StackOps CI Pipeline Hardening')->firstOrFail();

    $memberId = User::query()->where('email', 'member@example.com')->value('id');

    expect($deal->owner_id)->toBe($memberId)
        ->and($deal->created_by)->toBe($memberId)
        ->and(seededHistoryOf('StackOps CI Pipeline Hardening'))->toBe([
            '(none) > Lead @ 2026-05-03 12:00:00',
            'Lead > Proposal Sent @ 2026-05-10 12:00:00',
            'Proposal Sent > Negotiating @ 2026-05-24 12:00:00',
            'Negotiating > Won @ 2026-06-09 12:00:00',
        ])
        ->and($deal->won_at->toDateTimeString())->toBe('2026-06-09 12:00:00');
});

test('it consulting seeder keeps the reason, note and date of the lost history deals', function (string $title, string $lostAt, ?string $reason, ?string $note, string $ownerEmail) {
    seedHistoryAt($this);

    $deal = Deal::query()->with('lostReason')->where('title', $title)->firstOrFail();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_at->toDateTimeString())->toBe($lostAt)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lostReason?->name)->toBe($reason)
        ->and($deal->lost_reason_note)->toBe($note)
        ->and($deal->owner_id)->toBe(User::query()->where('email', $ownerEmail)->value('id'));
})->with([
    ['CloudShift Managed Services Pilot', '2026-09-20 12:00:00', 'No budget', 'Pilot postponed to next fiscal year.', 'test@example.com'],
    ['WebScale Performance Audit', '2026-07-25 12:00:00', null, null, 'member@example.com'],
    ['CyberNode Mobile App', '2026-07-18 12:00:00', 'Went with a competitor', null, 'admin@example.com'],
]);

test('it consulting seeder dates every history deal like its history', function () {
    seedHistoryAt($this);

    $original = ['TechCorp Infra Expansion', 'Cloudbase Cost Optimization Retainer', 'HexaSys React Platform Migration', 'SecurePeak Annual Security Program', 'InnoTech Transformation Advisory', 'NordicSec Pen Testing Extension'];
    $deals = Deal::query()->whereNotIn('title', $original)->get();

    expect($deals)->toHaveCount(12);

    foreach ($deals as $deal) {
        $history = $deal->stageChanges()->orderBy('changed_at')->orderBy('id')->get();
        $closedAt = $deal->status === DealStatus::Won ? $deal->won_at : $deal->lost_at;

        expect($history->first()->from_pipeline_stage_id)->toBeNull()
            ->and($deal->created_at->toDateTimeString())->toBe($history->first()->changed_at->toDateTimeString())
            ->and($closedAt->toDateTimeString())->toBe($history->last()->changed_at->toDateTimeString())
            ->and($deal->pipeline_stage_id)->toBe($history->last()->to_pipeline_stage_id);
    }
});

test('it consulting seeder splits the won value and lost reasons of the history deals', function () {
    seedHistoryAt($this);

    $wonPerOwner = Deal::query()->where('status', DealStatus::Won)->get()->groupBy('owner_id')
        ->map(fn ($deals): string => number_format((float) $deals->sum('value'), 2, '.', ''));
    $ownerId = fn (string $email): int => User::query()->where('email', $email)->value('id');
    $lostPerReason = Deal::query()->where('status', DealStatus::Lost)->with('lostReason')->get()
        ->countBy(fn (Deal $deal): string => $deal->lostReason?->name ?? 'none')->sortKeys()->all();

    expect($wonPerOwner[$ownerId('test@example.com')])->toBe('228000.00')
        ->and($wonPerOwner[$ownerId('admin@example.com')])->toBe('77000.00')
        ->and($wonPerOwner[$ownerId('member@example.com')])->toBe('27500.00')
        ->and(Deal::query()->where('status', DealStatus::Won)->where('owner_id', $ownerId('test@example.com'))->count())->toBe(4)
        ->and(Deal::query()->where('status', DealStatus::Won)->where('owner_id', $ownerId('admin@example.com'))->count())->toBe(2)
        ->and(Deal::query()->where('status', DealStatus::Won)->where('owner_id', $ownerId('member@example.com'))->count())->toBe(2)
        ->and($lostPerReason)->toBe(['Bad timing' => 1, 'No budget' => 2, 'Price too high' => 1, 'Went with a competitor' => 1, 'none' => 1]);
});

test('it consulting seeder does not move the test clock', function () {
    seedHistoryAt($this);

    expect(now()->toDateTimeString())->toBe('2026-10-21 12:00:00')
        ->and(now()->toImmutable()->toDateTimeString())->toBe('2026-10-21 12:00:00');
});

test('it consulting seeder adds no history twice and gives Globex Demo none', function () {
    $this->seed(ItConsultingSeeder::class);
    seedHistoryAt($this);

    $globex = Organization::query()->where('slug', 'globex-demo')->sole();

    expect(Deal::query()->count())->toBe(18)
        ->and(DealStageChange::query()->count())->toBe(54)
        ->and(Activity::query()->count())->toBe(44)
        ->and(Task::query()->count())->toBe(18)
        ->and(Deal::query()->forOrganization($globex->id)->count())->toBe(0)
        ->and(DealStageChange::query()->forOrganization($globex->id)->count())->toBe(0)
        ->and(Activity::query()->forOrganization($globex->id)->count())->toBe(0)
        ->and(Task::query()->forOrganization($globex->id)->count())->toBe(0);
});

/**
 * The 24 activities and 12 tasks of FEAT-79, found by their dates: everything on or after 90 days before the test clock.
 */
function recentSeededActivities(): Builder
{
    return Activity::query()->where('occurred_at', '>=', '2026-07-23 00:00:00');
}

test('it consulting seeder seeds recent activities and completed tasks of the three role users', function () {
    seedHistoryAt($this);

    $recent = recentSeededActivities();
    $perUser = (clone $recent)->with('user')->get()->countBy(fn (Activity $activity): string => $activity->user->email)->sortKeys()->all();
    $recentTasks = Task::query()->where('completed_at', '>=', '2026-07-23 00:00:00')->where('title', '!=', 'Send SecurePeak kickoff summary');

    expect(Activity::query()->count())->toBe(44)
        ->and(Task::query()->count())->toBe(18)
        ->and((clone $recent)->count())->toBe(24)
        ->and($perUser)->toBe(['admin@example.com' => 8, 'member@example.com' => 6, 'test@example.com' => 10])
        ->and(Activity::query()->where('user_id', User::query()->where('email', 'viewer@example.com')->value('id'))->count())->toBe(0)
        ->and((clone $recent)->whereNotNull('deal_id')->count())->toBe(0)
        ->and((clone $recent)->where('occurred_at', '>', now())->count())->toBe(0)
        ->and((clone $recentTasks)->count())->toBe(12)
        ->and((clone $recentTasks)->where('status', TaskStatus::Done)->count())->toBe(12)
        ->and((clone $recentTasks)->whereNotNull('deal_id')->count())->toBe(0)
        ->and((clone $recentTasks)->where('completed_at', '>', now())->count())->toBe(0)
        ->and(Task::query()->where('status', TaskStatus::Pending)->count())->toBe(5)
        ->and(Activity::query()->whereIn('contact_id', Contact::query()->whereIn('name', ['Hugo Fernandes', 'Florian Dupont'])->pluck('id'))->count())->toBe(0);
});

test('it consulting seeder keeps the exact values of a recent activity and a late task', function () {
    $organization = seedHistoryAt($this);

    $activity = Activity::query()->where('subject', 'Contract redlines walkthrough')->sole();
    $task = Task::query()->where('title', 'Follow up SecurePeak posture findings')->sole();
    $test = User::query()->where('email', 'test@example.com')->sole();

    expect($activity->type)->toBe(ActivityType::Call)
        ->and($activity->user_id)->toBe($test->id)
        ->and($activity->contact->name)->toBe('Marcus Chen')
        ->and($activity->occurred_at->utc()->toDateTimeString())->toBe('2026-10-19 09:30:00')
        ->and($activity->duration_minutes)->toBe(30)
        ->and($activity->outcome)->toBe(ActivityOutcome::Positive)
        ->and($activity->organization_id)->toBe($organization->id)
        ->and($task->contact->name)->toBe('Priya Nair')
        ->and(Task::query()->where('title', 'Call NordicSec about the extension')->sole()->contact->name)->toBe('Ingrid Svensson')
        ->and($task->status)->toBe(TaskStatus::Done)
        ->and($task->priority)->toBe(TaskPriority::Medium)
        ->and($task->type)->toBe(TaskType::FollowUp)
        ->and($task->owner_id)->toBe($test->id)
        ->and($task->created_by)->toBe($test->id)
        ->and($task->due_at->utc()->toDateTimeString())->toBe('2026-08-25 17:00:00')
        ->and($task->completed_at->utc()->toDateTimeString())->toBe('2026-08-27 11:00:00');
});

test('it consulting seeder gives the team activity leaderboard figures for this and last month', function () {
    seedHistoryAt($this);

    $rowsOf = fn (ReportPeriodPreset $preset): array => collect(TeamActivity::forPeriod(ReportPeriod::forPreset($preset), null))
        ->map(fn (array $row): array => [$row['member'], $row['activities'], $row['activities_breakdown'], $row['tasks_completed']])
        ->values()
        ->all();

    expect($rowsOf(ReportPeriodPreset::ThisMonth))->toBe([
        ['Test User', 4, 'Call 2 · Email 1 · Meeting 1', 3],
        ['Demo Admin', 3, 'Call 1 · Meeting 1 · WhatsApp 1', 2],
        ['Demo Member', 2, 'Call 1 · Email 1', 2],
        ['Total', 9, 'Call 4 · Email 2 · Meeting 2 · WhatsApp 1', 7],
    ])
        ->and($rowsOf(ReportPeriodPreset::LastMonth))->toBe([
            ['Demo Admin', 3, 'Call 1 · Email 1 · Meeting 1', 2],
            ['Demo Member', 1, 'WhatsApp 1', 0],
            ['Test User', 2, 'Email 1 · Meeting 1', 1],
            ['Total', 6, 'Call 1 · Email 2 · Meeting 2 · WhatsApp 1', 3],
        ]);
});

test('it consulting seeder adds no recent activity or task when it runs again later', function () {
    $this->seed(ItConsultingSeeder::class);
    seedHistoryAt($this);

    $globex = Organization::query()->where('slug', 'globex-demo')->sole();

    expect(Activity::query()->count())->toBe(44)
        ->and(Task::query()->count())->toBe(18)
        ->and(Activity::query()->forOrganization($globex->id)->count())->toBe(0)
        ->and(Task::query()->forOrganization($globex->id)->count())->toBe(0);
});

/**
 * The ledger of an invoice as "kind amount paid_on method recorder" lines, by id.
 *
 * @return array<int, string>
 */
function seededLedgerOf(string $number): array
{
    return Invoice::query()->where('invoice_number', $number)->firstOrFail()->payments()
        ->with('recordedBy')->orderBy('id')->get()
        ->map(fn (InvoicePayment $p): string => "{$p->kind->value} {$p->amount} {$p->paid_on->toDateString()} {$p->method->value} {$p->recordedBy->email}")
        ->all();
}

test('it consulting seeder seeds backdated invoices whose totals, statuses and ledgers agree', function () {
    seedHistoryAt($this);

    $statuses = Invoice::query()->get()->countBy(fn (Invoice $invoice): string => $invoice->status->value)->all();

    expect(Invoice::query()->count())->toBe(15)
        ->and(InvoicePayment::query()->count())->toBe(12)
        ->and(InvoicePayment::query()->where('kind', InvoicePaymentKind::Refund)->count())->toBe(1)
        ->and($statuses)->toEqual([
            InvoiceStatus::Paid->value => 7,
            InvoiceStatus::Partial->value => 2,
            InvoiceStatus::Sent->value => 2,
            InvoiceStatus::Overdue->value => 2,
            InvoiceStatus::Draft->value => 1,
            InvoiceStatus::Cancelled->value => 1,
        ])
        ->and(InvoicePayment::query()->where('paid_on', '>', '2026-10-21')->count())->toBe(0)
        ->and(Invoice::query()->where('issued_at', '>', '2026-10-21')->count())->toBe(0);

    foreach (Invoice::query()->with('payments')->get() as $invoice) {
        $net = $invoice->payments->sum(fn (InvoicePayment $p): float => $p->kind === InvoicePaymentKind::Payment ? (float) $p->amount : -(float) $p->amount);

        expect((float) $net)->toBe((float) $invoice->amount_paid, $invoice->invoice_number);
    }
});

test('it consulting seeder pays one invoice in two instalments through the ledger', function () {
    seedHistoryAt($this);

    $invoice = Invoice::query()->where('invoice_number', 'INV-2026-008')->firstOrFail();

    expect(seededLedgerOf('INV-2026-008'))->toBe([
        'payment 18000.00 2026-06-25 bank_transfer admin@example.com',
        'payment 18000.00 2026-07-10 bank_transfer admin@example.com',
    ])
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe('36000.00')
        ->and($invoice->paid_at->toDateString())->toBe('2026-07-10')
        ->and($invoice->creator->email)->toBe('admin@example.com')
        ->and($invoice->deal->title)->toBe('Cloudbase Kubernetes Migration')
        ->and($invoice->issued_at->toDateString())->toBe('2026-05-28')
        ->and($invoice->due_at->toDateString())->toBe('2026-06-27')
        ->and($invoice->created_at->toDateTimeString())->toBe('2026-05-28 12:00:00')
        ->and($invoice->payments()->orderBy('id')->first()->created_at->toDateTimeString())->toBe('2026-06-25 12:00:00');
});

test('it consulting seeder leaves one invoice partial after a deposit', function () {
    seedHistoryAt($this);

    $invoice = Invoice::query()->where('invoice_number', 'INV-2026-013')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($invoice->amount_paid)->toBe('10000.00')
        ->and($invoice->paid_at->toDateString())->toBe('2026-08-20')
        ->and($invoice->due_at->toDateString())->toBe('2026-09-06')
        ->and($invoice->creator->email)->toBe('test@example.com')
        ->and(seededLedgerOf('INV-2026-013'))->toBe(['payment 10000.00 2026-08-20 bank_transfer test@example.com']);
});

test('it consulting seeder cancels and refunds one invoice', function () {
    seedHistoryAt($this);

    $invoice = Invoice::query()->where('invoice_number', 'INV-2026-014')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->amount_paid)->toBe('0.00')
        ->and($invoice->paid_at)->toBeNull()
        ->and(seededLedgerOf('INV-2026-014'))->toBe([
            'payment 7500.00 2026-08-28 bank_transfer test@example.com',
            'refund 7500.00 2026-09-24 bank_transfer test@example.com',
        ]);
});

test('it consulting seeder splits the cash collected by month between the owners', function (?string $ownerEmail, array $values) {
    seedHistoryAt($this);

    $ownerId = $ownerEmail === null ? null : User::query()->where('email', $ownerEmail)->value('id');

    expect(DashboardKpis::cashCollectedByPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth)->trail(6), $ownerId))->toBe($values);
})->with([
    'everyone' => [null, [0.0, 66000.0, 36000.0, 44500.0, 33000.0, 26000.0]],
    'test' => ['test@example.com', [0.0, 48000.0, 0.0, 44500.0, 23500.0, 26000.0]],
    'admin' => ['admin@example.com', [0.0, 18000.0, 18000.0, 0.0, 0.0, 0.0]],
    'member' => ['member@example.com', [0.0, 0.0, 18000.0, 0.0, 9500.0, 0.0]],
]);

test('it consulting seeder shows the cash of last month and the aging buckets on the dashboard', function () {
    seedHistoryAt($this);

    Livewire::test(KpiOverviewWidget::class)
        ->assertSeeInOrder(['Cash Collected This Month', '$26,000.00', '-21.2% from $33,000.00']);

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['31–60 days late', '$23,000.00', '1 invoice', '61–90 days late', '$2,400.00', '1 invoice', 'Over 90 days late', '$4,500.00', '1 invoice']);
});

test('it consulting seeder adds no invoice or payment twice and gives Globex Demo none', function () {
    $this->seed(ItConsultingSeeder::class);
    seedHistoryAt($this);

    $globex = Organization::query()->where('slug', 'globex-demo')->sole();

    expect(Invoice::query()->count())->toBe(15)
        ->and(InvoicePayment::query()->count())->toBe(12)
        ->and(Invoice::query()->forOrganization($globex->id)->count())->toBe(0)
        ->and(InvoicePayment::query()->forOrganization($globex->id)->count())->toBe(0);
});
