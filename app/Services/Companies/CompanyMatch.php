<?php

namespace App\Services\Companies;

use App\Models\Company;

/**
 * The answer of `CompanyMatcher::match()`: a company was found, none matches, or the row can't be resolved.
 *
 * `$name` and `$domain` are the normalized inputs (name trimmed with its case kept, domain from the website).
 */
final readonly class CompanyMatch
{
    private function __construct(
        public ?Company $company,
        public ?string $reason,
        public ?string $name,
        public ?string $domain,
    ) {}

    public static function found(Company $company, ?string $name = null, ?string $domain = null): self
    {
        return new self($company, null, $name, $domain);
    }

    public static function notFound(?string $name = null, ?string $domain = null): self
    {
        return new self(null, null, $name, $domain);
    }

    public static function failed(string $reason, ?string $name = null, ?string $domain = null): self
    {
        return new self(null, $reason, $name, $domain);
    }

    public function isFound(): bool
    {
        return $this->company !== null;
    }

    public function isNotFound(): bool
    {
        return $this->company === null && $this->reason === null;
    }

    public function isFailed(): bool
    {
        return $this->reason !== null;
    }
}
