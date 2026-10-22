<?php

use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Filament\Actions\ExportReportTableAction;
use App\Filament\Widgets\Reports\RevenueByClientWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Models\User;
use App\Services\Imports\TextPreservingExcelReader;
use App\Support\Reports\DashboardFilters;
use App\Support\TemporaryFile;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Analytics\RevenueByClientFixture;
use Tests\Feature\Analytics\TeamActivityFixture;

const REPORT_EXPORT_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

beforeEach(function () {
    $this->exportDirectory = sys_get_temp_dir().'/crm-test-report-export-'.Str::random(12);
    mkdir($this->exportDirectory);

    $this->exportRows = function (Testable $component, string $extension): array {
        $path = $this->exportDirectory.'/read-back.'.$extension;
        file_put_contents($path, base64_decode((string) data_get($component->effects, 'download.content')));

        $rows = TextPreservingExcelReader::create($path, $extension)->noHeaderRow()->getRows()->values()->all();

        unlink($path);

        return $rows;
    };

    $this->exportTeam = fn (array $data = [], mixed $pageFilters = null): Testable => Livewire::test(TeamActivityWidget::class, ['pageFilters' => $pageFilters])
        ->callAction(TestAction::make('exportTable')->table(), data: $data + ['format' => ExportFormat::Xlsx->value]);

    $this->exportRevenue = fn (array $data = [], mixed $pageFilters = null): Testable => Livewire::test(RevenueByClientWidget::class, ['pageFilters' => $pageFilters])
        ->callAction(TestAction::make('exportTable')->table(), data: $data + ['format' => ExportFormat::Xlsx->value]);
});

afterEach(function () {
    TemporaryFile::useDirectory(null);

    array_map('unlink', glob($this->exportDirectory.'/*') ?: []);
    rmdir($this->exportDirectory);
});

test('team activity is exported as xlsx for this month', function () {
    $this->travelTo(TeamActivityFixture::now());
    TeamActivityFixture::build();

    $component = ($this->exportTeam)();

    $component->assertFileDownloaded('team-activity-2026-10-01-to-2026-10-31.xlsx', contentType: REPORT_EXPORT_XLSX);

    expect(($this->exportRows)($component, 'xlsx'))->toEqual([
        ['Member', 'Activities', 'Activities by type', 'Tasks done', 'Overdue tasks', 'New deals', 'Deals won', 'Won value (USD)', 'Win rate'],
        ['Bob Brown', 1, 'WhatsApp 1', 1, 0, 1, 1, 2000, '100% (1 of 1)'],
        ['Alice Anders', 4, 'Call 2 · Email 1 · Meeting 1', 1, 1, 3, 2, 1500, '67% (2 of 3)'],
        ['Fred Former (no longer a member)', 0, '', 0, 0, 0, 1, 300, '100% (1 of 1)'],
        ['Total', 5, 'Call 2 · Email 1 · Meeting 1 · WhatsApp 1', 2, 1, 4, 4, 3800, '80% (4 of 5)'],
    ]);
});

test('team activity follows the owner filter and is exported as a csv with a byte order mark', function () {
    $this->travelTo(TeamActivityFixture::now());
    $team = TeamActivityFixture::build();

    $component = ($this->exportTeam)(['format' => ExportFormat::Csv->value], ['owner' => (string) $team->bob->id]);

    $component->assertFileDownloaded('team-activity-2026-10-01-to-2026-10-31.csv', contentType: ExportFormat::Csv->contentType());

    expect(str_starts_with(base64_decode((string) data_get($component->effects, 'download.content')), "\xEF\xBB\xBF"))->toBeTrue();

    $rows = ($this->exportRows)($component, 'csv');

    expect(array_map(fn (array $row): string => (string) reset($row), array_slice($rows, 1)))->toBe(['Bob Brown', 'Total']);
});

test('the period filter changes the file name', function () {
    $this->travelTo(TeamActivityFixture::now());
    TeamActivityFixture::build();

    ($this->exportTeam)([], ['period' => 'last_month'])
        ->assertFileDownloaded('team-activity-2026-09-01-to-2026-09-30.xlsx');
});

test('members and clients of another organization are not exported', function () {
    $this->travelTo(TeamActivityFixture::now());
    TeamActivityFixture::build();

    $component = ($this->exportTeam)();

    expect(json_encode(($this->exportRows)($component, 'xlsx')))->not->toContain('Vera Viewer')->not->toContain('Carol Clark');
});

test('revenue by client is exported as xlsx for this month', function () {
    $this->travelTo(RevenueByClientFixture::now());
    RevenueByClientFixture::build();

    $component = ($this->exportRevenue)();

    $component->assertFileDownloaded('revenue-by-client-2026-10-01-to-2026-10-31.xlsx', contentType: REPORT_EXPORT_XLSX);

    expect(($this->exportRows)($component, 'xlsx'))->toEqual([
        ['Client', 'Type', 'Collected (USD)', 'Share', 'Outstanding (USD)'],
        ['Northwind', 'Company', 6000, '41%', 3000],
        ['Umbrella (deleted)', 'Company', 4000, '28%', 0],
        ['Ivan Ivanov', 'Contact', 2500, '17%', 2500],
        ['Zoe Zimmer', 'Contact', 2000, '14%', 1000],
        ['Initech', 'Company', 0, '0%', 1500],
        ['Total', '', 14500, '100%', 8000],
    ]);

    expect(json_encode(($this->exportRows)($component, 'xlsx')))->not->toContain('Gus Globex')->not->toContain('Hooli');
});

test('revenue by client follows the owner filter', function () {
    $this->travelTo(RevenueByClientFixture::now());
    $money = RevenueByClientFixture::build();

    $component = ($this->exportRevenue)([], ['owner' => (string) $money->bob->id]);

    expect(json_encode(($this->exportRows)($component, 'xlsx')))->toContain('Umbrella (deleted)')->not->toContain('Northwind');
});

test('nothing is downloaded when the revenue table is empty', function () {
    $this->travelTo(RevenueByClientFixture::now());
    RevenueByClientFixture::build(withInvoices: false);

    $component = ($this->exportRevenue)();

    $component->assertNotified('Nothing to export.');

    expect(data_get($component->effects, 'download'))->toBeNull();
});

test('only admins and the owner can export a table', function (string $widget, OrganizationRole $role, bool $visible) {
    $this->travelTo(TeamActivityFixture::now());
    $org = $widget === TeamActivityWidget::class ? TeamActivityFixture::build()->org : RevenueByClientFixture::build()->org;

    $user = User::factory()->onboardingCompleted()->create();
    $org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($org);

    $component = Livewire::test($widget, ['pageFilters' => null]);

    if ($visible) {
        $component->assertActionVisible(TestAction::make('exportTable')->table());

        return;
    }

    $component->assertActionHidden(TestAction::make('exportTable')->table())
        ->call('mountAction', 'exportTable', [], ['table' => true])
        ->assertActionNotMounted()
        ->call('callMountedAction');

    expect(data_get($component->effects, 'download'))->toBeNull();
})->with([
    'owner team' => [TeamActivityWidget::class, OrganizationRole::Owner, true],
    'admin team' => [TeamActivityWidget::class, OrganizationRole::Admin, true],
    'member team' => [TeamActivityWidget::class, OrganizationRole::Member, false],
    'viewer team' => [TeamActivityWidget::class, OrganizationRole::Viewer, false],
    'admin revenue' => [RevenueByClientWidget::class, OrganizationRole::Admin, true],
    'member revenue' => [RevenueByClientWidget::class, OrganizationRole::Member, false],
    'viewer revenue' => [RevenueByClientWidget::class, OrganizationRole::Viewer, false],
]);

test('a formula typed as a name stays text', function () {
    $this->travelTo(TeamActivityFixture::now());
    $team = TeamActivityFixture::build();

    $formula = '=HYPERLINK("https://evil.test","x")';
    $team->bob->update(['name' => $formula]);

    $csv = ($this->exportRows)(($this->exportTeam)(['format' => ExportFormat::Csv->value]), 'csv');
    $xlsxComponent = ($this->exportTeam)(['format' => ExportFormat::Xlsx->value]);
    $xlsx = ($this->exportRows)($xlsxComponent, 'xlsx');

    $path = $this->exportDirectory.'/formula.xlsx';
    file_put_contents($path, base64_decode((string) data_get($xlsxComponent->effects, 'download.content')));
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($csv[1][0])->toBe("'".$formula)
        ->and($xlsx[1][0])->toBe($formula)
        ->and($sheet)->not->toContain('<f>');
});

test('a client name typed as a formula stays text', function () {
    $this->travelTo(RevenueByClientFixture::now());
    $money = RevenueByClientFixture::build();

    $formula = '=HYPERLINK("https://evil.test","x")';
    $money->companies['Northwind']->update(['name' => $formula]);

    $csv = ($this->exportRows)(($this->exportRevenue)(['format' => ExportFormat::Csv->value]), 'csv');

    expect($csv[1][0])->toBe("'".$formula);
});

test('the temporary file is deleted after the download', function () {
    TemporaryFile::useDirectory($this->exportDirectory);
    $this->travelTo(TeamActivityFixture::now());
    TeamActivityFixture::build();

    ($this->exportTeam)()->assertFileDownloaded('team-activity-2026-10-01-to-2026-10-31.xlsx');

    expect(glob($this->exportDirectory.'/*'))->toBe([]);
});

test('the temporary file is deleted when writing fails', function () {
    TemporaryFile::useDirectory($this->exportDirectory);

    $action = ExportReportTableAction::make(
        'broken',
        'broken',
        DashboardFilters::fromPageFilters(null),
        fn (): array => ['headers' => ['A'], 'rows' => [[[]]]],
    );

    expect(fn () => $action->getActionFunction()(['format' => ExportFormat::Xlsx->value]))->toThrow(TypeError::class)
        ->and(glob($this->exportDirectory.'/*'))->toBe([]);
});
