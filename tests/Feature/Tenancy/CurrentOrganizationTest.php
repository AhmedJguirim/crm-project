<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->acmeUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->globexUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->acme = $this->acmeUser->personalOrganization();
    $this->globex = $this->globexUser->personalOrganization();
});

describe('CurrentOrganization', function () {
    it('returns the panel tenant typed as an organization', function () {
        $this->actingAs($this->acmeUser);
        Filament::setTenant($this->acme);

        expect(CurrentOrganization::find())->toBeInstanceOf(Organization::class)
            ->and(CurrentOrganization::find()->id)->toBe($this->acme->id)
            ->and(CurrentOrganization::get())->toBeInstanceOf(Organization::class)
            ->and(CurrentOrganization::get()->id)->toBe($this->acme->id);
    });

    it('returns null from find() and throws from get() without a tenant', function () {
        Filament::setTenant(null);

        expect(CurrentOrganization::find())->toBeNull();

        expect(fn () => CurrentOrganization::get())->toThrow(LogicException::class, 'There is no current organization.');
    });

    it('does not read the job context', function () {
        Filament::setTenant(null);

        $seen = app(TenantContext::class)->run($this->acme->id, fn (): array => [
            'current' => CurrentOrganization::find(),
            'context' => app(TenantContext::class)->id(),
        ]);

        expect($seen['current'])->toBeNull()
            ->and($seen['context'])->toBe($this->acme->id);
    });

    it('keeps the panel tenant ahead of the job context in TenantContext', function () {
        $this->actingAs($this->globexUser);
        Filament::setTenant($this->globex);

        $id = app(TenantContext::class)->run($this->acme->id, fn (): ?int => app(TenantContext::class)->id());

        expect($id)->toBe($this->globex->id);
    });
});
