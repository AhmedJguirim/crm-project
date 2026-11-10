<?php

use App\Enums\DealStatus;
use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Jobs\ExportDealsJob;
use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->org->update(['timezone' => 'Pacific/Auckland']);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->deal = fn (string $title, string $createdAt, int $value, array $attributes = []): Deal => Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->owner->id,
        'title' => $title,
        'value' => $value,
        'created_at' => $createdAt,
        'notes' => null,
        ...$attributes,
    ]);

    $this->alpha = ($this->deal)('Alpha', '2026-11-01 10:00:00', 300, ['status' => DealStatus::Won, 'won_at' => '2026-11-02 10:00:00']);
    $this->bravo = ($this->deal)('Bravo', '2026-11-02 10:00:00', 100);
    $this->charlie = ($this->deal)('Charlie', '2026-11-03 10:00:00', 200);

    $this->pushedJob = function (): array {
        return Queue::pushed(ExportDealsJob::class)
            ->map(fn (ExportDealsJob $job): array => (fn (): array => [
                'ids' => $this->dealIds,
                'organization' => $this->organizationId,
                'user' => $this->userId,
                'format' => $this->format,
                'name' => $this->downloadName,
            ])->call($job))
            ->values()
            ->all();
    };
});

describe('an admin', function () {
    beforeEach(function () {
        $this->admin = ($this->actAs)(OrganizationRole::Admin);
    });

    it('exports what the list shows, in the order of the list', function () {
        Livewire::test(ListDeals::class)
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->charlie->id, $this->bravo->id, $this->alpha->id])
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['format'])->toBe('xlsx');
    });

    it('follows the active tab and the filters', function () {
        $this->bravo->forceFill(['owner_id' => $this->admin->id])->saveQuietly();

        Livewire::test(ListDeals::class)
            ->set('activeTab', 'mine')
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bravo->id]);

        Queue::fake();

        Livewire::test(ListDeals::class)
            ->set('activeTab', 'all')
            ->filterTable('status', DealStatus::Won->value)
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id]);
    });

    it('follows the sort and the search', function () {
        Livewire::test(ListDeals::class)
            ->sortTable('value', 'asc')
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bravo->id, $this->charlie->id, $this->alpha->id]);

        Queue::fake();

        Livewire::test(ListDeals::class)
            ->searchTable('Bravo')
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bravo->id]);
    });

    it('exports all pages, not only the one shown', function () {
        Deal::factory()->inStage('Negotiating')->count(30)->create(['organization_id' => $this->org->id, 'created_by' => $this->owner->id]);

        Livewire::test(ListDeals::class)->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(33);
    });

    it('exports only the deals of the organization in use', function () {
        Deal::factory()->inStage('Negotiating')->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id]);

        Livewire::test(ListDeals::class)->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(3);
    });

    it('names the file with the local date of the organization', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        Livewire::test(ListDeals::class)->callAction('exportDeals', ['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['name'])->toBe('deals-2026-10-07.csv');
    });

    it('defaults to Excel and refuses another format', function () {
        Livewire::test(ListDeals::class)
            ->mountAction('exportDeals')
            ->assertSchemaStateSet(['format' => ExportFormat::Xlsx])
            ->setActionData(['format' => 'pdf'])
            ->callMountedAction()
            ->assertHasActionErrors(['format']);

        Queue::assertNotPushed(ExportDealsJob::class);
    });

    it('warns when there is nothing to export', function () {
        Livewire::test(ListDeals::class)
            ->searchTable('nobody matches this')
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Nothing to export.');

        Queue::assertNotPushed(ExportDealsJob::class);
    });

    it('exports the selected deals as a csv, whatever the sort', function () {
        Livewire::test(ListDeals::class)
            ->sortTable('value', 'asc')
            ->callTableBulkAction('exportSelected', [$this->alpha, $this->charlie], ['format' => ExportFormat::Csv->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toEqualCanonicalizing([$this->alpha->id, $this->charlie->id])
            ->and($jobs[0]['format'])->toBe('csv')
            ->and($jobs[0]['user'])->toBe($this->admin->id);
    });

    it('exports nothing of another organization when the selection is forged', function () {
        $foreign = Deal::factory()->inStage('Negotiating')->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id, 'title' => 'Globex secret']);

        Livewire::test(ListDeals::class)
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', [(string) $this->alpha->id, (string) $foreign->id])
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->fillForm(['format' => ExportFormat::Csv->value])
            ->callMountedAction();

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id]);
    });

    it('writes a file with only the selected deals', function () {
        Storage::fake('local');

        Livewire::test(ListDeals::class)
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', [(string) $this->alpha->id, (string) $this->charlie->id])
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->fillForm(['format' => ExportFormat::Csv->value])
            ->callMountedAction();

        Queue::pushed(ExportDealsJob::class)->sole()->handle();

        $file = Storage::disk('local')->files('exports')[0];
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file), 'csv')->getRows()->all();

        expect(array_column($rows, 'title'))->toEqualCanonicalizing(['Alpha', 'Charlie']);
    });
});

describe('the owner', function () {
    it('exports too', function () {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);

        Livewire::test(ListDeals::class)
            ->assertActionVisible('exportDeals')
            ->assertTableBulkActionVisible('exportSelected')
            ->callAction('exportDeals', ['format' => ExportFormat::Xlsx->value]);

        Queue::assertPushed(ExportDealsJob::class, 1);
    });
});

describe('a member or a viewer', function () {
    it('sees neither export action', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListDeals::class)
            ->assertActionHidden('exportDeals')
            ->assertTableBulkActionHidden('exportSelected');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('is refused when calling the actions anyway', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListDeals::class)
            ->call('mountAction', 'exportDeals', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Livewire::test(ListDeals::class)
            ->set('selectedTableRecords', [$this->alpha->getKey()])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Queue::assertNotPushed(ExportDealsJob::class);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
