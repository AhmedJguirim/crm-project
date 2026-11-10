<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\ExportFormat;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\ActivityExportWriter;
use App\Services\Imports\TextPreservingExcelReader;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->writer = fn (string $timezone = 'Pacific/Auckland'): ActivityExportWriter => new ActivityExportWriter($timezone);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann@example.com']);

    $this->activity = fn (array $attributes = []): Activity => Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => null,
        'type' => ActivityType::Note,
        'duration_minutes' => null,
        'subject' => 'Plain activity',
        'notes' => null,
        'outcome' => null,
        ...$attributes,
    ]);

    $this->load = fn (Activity $activity): Activity => Activity::query()
        ->with([
            'user',
            'contact' => fn (BelongsTo $query) => $query->withTrashed(),
            'deal',
            'company' => fn (BelongsTo $query) => $query->withTrashed(),
        ])
        ->findOrFail($activity->id);

    $this->cells = fn (Activity $activity): array => array_combine(
        ActivityExportWriter::COLUMNS,
        ($this->writer)()->row(($this->load)($activity), ExportFormat::Csv),
    );
});

it('names the timezone on the three timestamps only', function () {
    expect(($this->writer)()->headers())->toBe([
        'id', 'type', 'occurred at (Pacific/Auckland)', 'duration (minutes)', 'subject', 'outcome', 'notes', 'logged by',
        'contact id', 'contact', 'contact email', 'deal id', 'deal', 'company id', 'company',
        'created at (Pacific/Auckland)', 'updated at (Pacific/Auckland)',
    ])->and(($this->writer)()->headers())->toHaveCount(17);
});

it('writes a call linked to a contact, a deal and a company, in the organization timezone', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id, 'title' => 'Website redesign']);
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Studio']);
    $olga = User::factory()->create(['email' => 'olga@example.com']);
    $activity = ($this->activity)([
        'type' => ActivityType::Call,
        'duration_minutes' => 25,
        'subject' => 'Kick-off call',
        'outcome' => ActivityOutcome::Positive,
        'notes' => 'Sent the agenda',
        'user_id' => $olga->id,
        'deal_id' => $deal->id,
        'company_id' => $company->id,
        'occurred_at' => '2026-07-01 22:30:00',
        'created_at' => '2026-03-28 23:30:00',
        'updated_at' => '2026-07-01 22:30:00',
    ]);

    expect(($this->writer)()->row(($this->load)($activity), ExportFormat::Csv))->toBe([
        $activity->id, 'Call', '02-07-2026 10:30', 25, 'Kick-off call', 'Positive', 'Sent the agenda', 'olga@example.com',
        $this->contact->id, 'Ann Lee', 'ann@example.com', $deal->id, 'Website redesign', $company->id, 'Acme Studio',
        '29-03-2026 12:30', '02-07-2026 10:30',
    ]);
});

it('labels the two-word type and outcome', function () {
    $activity = ($this->activity)(['type' => ActivityType::InPerson, 'outcome' => ActivityOutcome::FollowUpNeeded]);

    $cells = ($this->cells)($activity);

    expect($cells['type'])->toBe('In-Person')
        ->and($cells['outcome'])->toBe('Follow-up Needed');
});

it('writes empty cells for a company-only note without duration, subject, outcome, notes or user', function () {
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Studio']);
    $activity = Activity::factory()->onlyOnCompany($company)->create([
        'type' => ActivityType::Note,
        'user_id' => null,
        'duration_minutes' => null,
        'subject' => null,
        'outcome' => null,
        'notes' => null,
    ]);

    $cells = ($this->cells)($activity);

    expect(array_intersect_key($cells, array_flip(['duration (minutes)', 'subject', 'outcome', 'notes', 'logged by', 'contact id', 'contact', 'contact email', 'deal id', 'deal'])))
        ->each->toBe('')
        ->and($cells['company id'])->toBe($company->id)
        ->and($cells['company'])->toBe('Acme Studio');
});

it('keeps the names of a soft-deleted contact and a soft-deleted company', function () {
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme Studio']);
    $activity = ($this->activity)(['company_id' => $company->id]);
    $this->contact->delete();
    $company->delete();

    $cells = ($this->cells)($activity);

    expect($cells['contact id'])->toBe($this->contact->id)
        ->and($cells['contact'])->toBe('Ann Lee')
        ->and($cells['contact email'])->toBe('ann@example.com')
        ->and($cells['company id'])->toBe($company->id)
        ->and($cells['company'])->toBe('Acme Studio');
});

it('writes empty deal cells when the deal no longer exists', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id]);
    $activity = ($this->activity)(['deal_id' => $deal->id]);
    $deal->delete();

    $cells = ($this->cells)($activity);

    expect($cells['deal id'])->toBe('')
        ->and($cells['deal'])->toBe('');
});

it('writes zero minutes as a number and not as an empty cell', function () {
    $activity = ($this->activity)(['type' => ActivityType::Call, 'duration_minutes' => 0]);

    expect(($this->cells)($activity)['duration (minutes)'])->toBe(0);
});

it('neutralises a formula in a CSV and keeps it as text in an xlsx', function () {
    $activity = ($this->activity)(['subject' => '=HYPERLINK("http://evil.test","x")', 'notes' => '@SUM(1+1)']);
    $csvPath = tempnam(sys_get_temp_dir(), 'activity-export').'.csv';
    $xlsxPath = tempnam(sys_get_temp_dir(), 'activity-export').'.xlsx';

    ($this->writer)()->write($csvPath, ExportFormat::Csv, [[($this->load)($activity)]]);
    $count = ($this->writer)()->write($xlsxPath, ExportFormat::Xlsx, [[($this->load)($activity)]]);

    $csv = SimpleExcelReader::create($csvPath, 'csv')->getRows()->first();
    $xlsx = TextPreservingExcelReader::create($xlsxPath, 'xlsx')->getRows()->first();
    unlink($csvPath);
    unlink($xlsxPath);

    expect($count)->toBe(1)
        ->and($csv['subject'])->toBe('\'=HYPERLINK("http://evil.test","x")')
        ->and($csv['notes'])->toBe("'@SUM(1+1)")
        ->and($xlsx['subject'])->toBe('=HYPERLINK("http://evil.test","x")');
});

it('neutralises a formula in the contact name and the company name of a CSV', function () {
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => '-cmd|calc']);
    $this->contact->update(['name' => '+cmd|calc']);
    $activity = ($this->activity)(['company_id' => $company->id]);

    $cells = ($this->cells)($activity);

    expect($cells['contact'])->toBe("'+cmd|calc")
        ->and($cells['company'])->toBe("'-cmd|calc");
});

it('writes empty company cells for an activity without a company', function () {
    $cells = ($this->cells)(($this->activity)());

    expect($cells['company id'])->toBe('')
        ->and($cells['company'])->toBe('');
});
