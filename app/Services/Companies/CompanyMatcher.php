<?php

namespace App\Services\Companies;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Finds the company of an organization that a name and a website designate. The website decides: with a domain, the
 * domain is matched first and the name only picks among companies sharing it or companies without a domain; without
 * one, the name alone is matched. It never writes.
 *
 * The in-memory memo (domain and lowercase name to company id) only saves queries during one import. It is filled
 * when a match is unambiguous, and by `remember()` for a company the import created.
 */
class CompanyMatcher
{
    /** @var array<string, int> */
    private array $idsByDomain = [];

    /** @var array<string, int> */
    private array $idsByName = [];

    private function __construct(private readonly int $organizationId) {}

    public static function forOrganization(int $organizationId): self
    {
        return new self($organizationId);
    }

    /**
     * @throws InvalidArgumentException when both the name and the website are blank
     */
    public function match(?string $name, ?string $website): CompanyMatch
    {
        $name = $this->normalizeName($name);
        $website = trim((string) $website);

        if ($name === null && $website === '') {
            throw new InvalidArgumentException('A company needs a name or a website to be matched.');
        }

        $domain = null;

        if ($website !== '') {
            $domain = Company::domainFrom($website);

            if ($domain === null) {
                return CompanyMatch::failed("Invalid value for field 'company website': {$website}", $name);
            }
        }

        return $domain === null
            ? $this->matchByName($name)
            : $this->matchByDomain($domain, $name);
    }

    /**
     * Records a company the import has just created, so the next rows find it without a query.
     */
    public function remember(Company $company): void
    {
        $name = $this->normalizeName($company->name);

        if ($company->domain !== null) {
            $this->idsByDomain[$company->domain] = $company->getKey();

            if ($name !== null) {
                unset($this->idsByName[mb_strtolower($name)]);
            }

            return;
        }

        if ($name !== null) {
            $this->idsByName[mb_strtolower($name)] = $company->getKey();
        }
    }

    /**
     * Empties the memo. An import that renames a company or changes its website calls it, so no later row is matched
     * through a stale entry. The memo only saves queries, so forgetting it is always safe.
     */
    public function forgetRemembered(): void
    {
        $this->idsByDomain = [];
        $this->idsByName = [];
    }

    private function matchByDomain(string $domain, ?string $name): CompanyMatch
    {
        $remembered = $this->rememberedByDomain($domain);

        if ($remembered !== null) {
            return CompanyMatch::found($remembered, $name, $domain);
        }

        $sharing = $this->companies()->where('domain', $domain)->orderBy('id')->get();

        if ($sharing->count() === 1) {
            return $this->found($sharing->first(), $name, $domain, byDomain: true);
        }

        if ($sharing->count() > 1) {
            $named = $name === null ? collect() : $sharing->filter(
                fn (Company $company): bool => $this->sameName($company->name, $name),
            );

            if ($named->count() === 1) {
                return $this->found($named->first(), $name, $domain);
            }

            return CompanyMatch::failed(
                "Several companies use the website {$domain}. Merge them or import this row by hand.",
                $name,
                $domain,
            );
        }

        $trashed = $this->trashedCompanies()->where('domain', $domain)->orderBy('id')->first();

        if ($trashed !== null) {
            return $this->inTrash($trashed, $name, $domain);
        }

        if ($name === null) {
            return CompanyMatch::notFound($name, $domain);
        }

        return $this->matchWithoutDomainByName($name, $domain);
    }

    private function matchWithoutDomainByName(string $name, string $domain): CompanyMatch
    {
        $remembered = $this->rememberedByName($name);

        if ($remembered !== null && $remembered->domain === null) {
            return CompanyMatch::found($remembered, $name, $domain);
        }

        $candidates = $this->namedCompanies($this->companies(), $name)->whereNull('domain')->orderBy('id')->get();

        return $this->resolveByName($candidates, $name, $domain, remember: false);
    }

    private function matchByName(string $name): CompanyMatch
    {
        $remembered = $this->rememberedByName($name);

        if ($remembered !== null) {
            return CompanyMatch::found($remembered, $name);
        }

        $candidates = $this->namedCompanies($this->companies(), $name)->orderBy('id')->get();

        if ($candidates->isEmpty()) {
            $trashed = $this->namedCompanies($this->trashedCompanies(), $name)->orderBy('id')->first();

            if ($trashed !== null) {
                return $this->inTrash($trashed, $name, null);
            }
        }

        return $this->resolveByName($candidates, $name, null, remember: true);
    }

    /**
     * @param  Collection<int, Company>  $candidates
     */
    private function resolveByName(Collection $candidates, string $name, ?string $domain, bool $remember): CompanyMatch
    {
        if ($candidates->count() > 1) {
            return CompanyMatch::failed(
                "Several companies are named {$name}. Add the company website to choose.",
                $name,
                $domain,
            );
        }

        if ($candidates->isEmpty()) {
            return CompanyMatch::notFound($name, $domain);
        }

        $company = $candidates->first();

        if ($remember) {
            $this->idsByName[mb_strtolower($name)] = $company->getKey();
        }

        return CompanyMatch::found($company, $name, $domain);
    }

    private function found(Company $company, ?string $name, string $domain, bool $byDomain = false): CompanyMatch
    {
        if ($byDomain) {
            $this->idsByDomain[$domain] = $company->getKey();
        }

        return CompanyMatch::found($company, $name, $domain);
    }

    private function inTrash(Company $company, ?string $name, ?string $domain): CompanyMatch
    {
        return CompanyMatch::failed("The company {$company->name} is in the trash. Restore it first.", $name, $domain);
    }

    private function rememberedByDomain(string $domain): ?Company
    {
        $id = $this->idsByDomain[$domain] ?? null;

        if ($id === null) {
            return null;
        }

        $company = $this->companies()->find($id);

        if ($company === null) {
            unset($this->idsByDomain[$domain]);
        }

        return $company;
    }

    private function rememberedByName(string $name): ?Company
    {
        $key = mb_strtolower($name);
        $id = $this->idsByName[$key] ?? null;

        if ($id === null) {
            return null;
        }

        $company = $this->companies()->find($id);

        if ($company === null) {
            unset($this->idsByName[$key]);
        }

        return $company;
    }

    /**
     * @return Builder<Company>
     */
    private function companies(): Builder
    {
        return Company::query()->forOrganization($this->organizationId);
    }

    /**
     * @return Builder<Company>
     */
    private function trashedCompanies(): Builder
    {
        return Company::query()->forOrganization($this->organizationId)->onlyTrashed();
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function namedCompanies(Builder $query, string $name): Builder
    {
        return $query->whereRaw('lower(trim(name)) = ?', [mb_strtolower($name)]);
    }

    private function sameName(string $companyName, string $name): bool
    {
        return mb_strtolower(trim($companyName)) === mb_strtolower($name);
    }

    private function normalizeName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }
}
