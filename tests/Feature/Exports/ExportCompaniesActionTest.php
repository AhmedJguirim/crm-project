<?php

use App\Enums\CompanyIndustry;
use App\Enums\ExportFormat;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Jobs\ExportCompaniesJob;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

    $this->client = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Client']);
    $this->partner = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Partner']);

    $this->company = fn (string $name, CompanyType $type, CompanyIndustry $industry): Company => Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'company_type_id' => $type->id,
        'industry' => $industry,
        'custom_field_values' => [],
    ]);

    $this->ann = ($this->company)('Ann Ltd', $this->client, CompanyIndustry::Software);
    $this->bob = ($this->company)('Bob Inc', $this->partner, CompanyIndustry::Software);
    $this->cid = ($this->company)('Cid SA', $this->client, CompanyIndustry::FinanceBanking);

    $this->pushedJob = function (): array {
        return Queue::pushed(ExportCompaniesJob::class)
            ->map(fn (ExportCompaniesJob $job): array => (fn (): array => [
                'ids' => $this->companyIds,
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
        Livewire::test(ListCompanies::class)
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->ann->id, $this->bob->id, $this->cid->id])
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['format'])->toBe('xlsx');
    });

    it('applies the type filter, the industry filter and the search', function () {
        Livewire::test(ListCompanies::class)
            ->filterTable('company_type_id', [$this->client->id])
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->ann->id, $this->cid->id]);

        Queue::fake();

        Livewire::test(ListCompanies::class)
            ->filterTable('industry', [CompanyIndustry::Software->value])
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->ann->id, $this->bob->id]);

        Queue::fake();

        Livewire::test(ListCompanies::class)
            ->searchTable('Bob')
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bob->id]);
    });

    it('keeps the sort by the number of contacts', function () {
        $this->cid->contacts()->attach(Contact::factory()->count(2)->create(['organization_id' => $this->org->id])->modelKeys());
        $this->bob->contacts()->attach(Contact::factory()->create(['organization_id' => $this->org->id])->id);

        Livewire::test(ListCompanies::class)
            ->sortTable('contacts_count', 'desc')
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->cid->id, $this->bob->id, $this->ann->id]);
    });

    it('exports all pages, not only the one shown', function () {
        Company::factory()->count(30)->create(['organization_id' => $this->org->id, 'custom_field_values' => []]);

        Livewire::test(ListCompanies::class)->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(33);
    });

    it('applies the trashed filter', function () {
        $dan = ($this->company)('Dan Co', $this->client, CompanyIndustry::Software);
        $dan->delete();

        Livewire::test(ListCompanies::class)
            ->filterTable('trashed', 0)
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$dan->id]);
    });

    it('exports only the companies of the organization in use', function () {
        Company::factory()->create(['organization_id' => User::factory()->withPersonalOrganization()->create()->personalOrganization()->id]);

        Livewire::test(ListCompanies::class)->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(3);
    });

    it('names the file with the local date of the organization', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        Livewire::test(ListCompanies::class)->callAction('exportCompanies', ['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['name'])->toBe('companies-2026-10-07.csv');
    });

    it('defaults to Excel and refuses another format', function () {
        Livewire::test(ListCompanies::class)
            ->mountAction('exportCompanies')
            ->assertSchemaStateSet(['format' => ExportFormat::Xlsx])
            ->setActionData(['format' => 'pdf'])
            ->callMountedAction()
            ->assertHasActionErrors(['format']);

        Queue::assertNotPushed(ExportCompaniesJob::class);
    });

    it('warns when there is nothing to export', function () {
        Livewire::test(ListCompanies::class)
            ->searchTable('nobody matches this')
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Nothing to export.');

        Queue::assertNotPushed(ExportCompaniesJob::class);
    });

    it('exports the selected companies as a csv', function () {
        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('exportSelected', [$this->ann, $this->cid], ['format' => ExportFormat::Csv->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toEqualCanonicalizing([$this->ann->id, $this->cid->id])
            ->and($jobs[0]['format'])->toBe('csv')
            ->and($jobs[0]['user'])->toBe($this->admin->id);
    });
});

describe('the owner', function () {
    it('exports too', function () {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);

        Livewire::test(ListCompanies::class)
            ->assertActionVisible('exportCompanies')
            ->assertTableBulkActionVisible('exportSelected')
            ->callAction('exportCompanies', ['format' => ExportFormat::Xlsx->value]);

        Queue::assertPushed(ExportCompaniesJob::class, 1);
    });
});

describe('a member or a viewer', function () {
    it('sees neither export action', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListCompanies::class)
            ->assertActionHidden('exportCompanies')
            ->assertTableBulkActionHidden('exportSelected');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('is refused when calling the actions anyway', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListCompanies::class)
            ->call('mountAction', 'exportCompanies', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Livewire::test(ListCompanies::class)
            ->set('selectedTableRecords', [$this->ann->getKey()])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Queue::assertNotPushed(ExportCompaniesJob::class);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
