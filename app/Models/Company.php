<?php

namespace App\Models;

use App\Enums\CompanyIndustry;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\EnforcesUniqueCustomFieldValues;
use App\Models\Concerns\HasCustomFieldValues;
use App\Models\Concerns\PreventsDeletionWhileUsedInSegments;
use App\Models\Concerns\PreventsForceDeletion;
use App\Observers\CompanyObserver;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[ObservedBy([CompanyObserver::class])]
class Company extends Model
{
    use BelongsToOrganization;
    use EnforcesUniqueCustomFieldValues;
    use HasCustomFieldValues;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use PreventsDeletionWhileUsedInSegments;
    use PreventsForceDeletion;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_type_id',
        'address_id',
        'industry',
        'name',
        'website',
        'phone',
        'employees',
        'annual_revenue',
        'notes',
        'custom_field_values',
    ];

    protected function casts(): array
    {
        return [
            'industry' => CompanyIndustry::class,
            'employees' => 'integer',
            'annual_revenue' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            if (! $company->exists || $company->isDirty('website')) {
                $company->domain = static::domainFrom($company->website);
            }
        });
    }

    /**
     * The website is stored as typed, only trimmed; blank means no website.
     */
    protected function website(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => blank($value) ? null : trim($value),
        );
    }

    /**
     * The domain of a website: lowercase, without "www." and any port, path or scheme. Null for a blank value, a
     * value without a host, or a host without a dot (such as "localhost").
     */
    public static function domainFrom(?string $website): ?string
    {
        $website = trim((string) $website);

        if ($website === '') {
            return null;
        }

        $scheme = static::schemeOf($website);

        if ($scheme !== null && ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($scheme === null) {
            $website = "https://{$website}";
        }

        $host = parse_url($website, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = Str::of($host)->lower()->replaceMatches('/^www\./', '')->toString();

        return preg_match('/^[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)+$/u', $host) === 1 ? $host : null;
    }

    /**
     * The website as a link: with https:// added when it has no scheme, and only when the scheme is http or https, so
     * a stored "javascript://…" never becomes a link.
     */
    public function websiteUrl(): ?string
    {
        if (blank($this->website)) {
            return null;
        }

        $scheme = static::schemeOf($this->website);

        if ($scheme === null) {
            return "https://{$this->website}";
        }

        return in_array($scheme, ['http', 'https'], true) ? $this->website : null;
    }

    /**
     * The lowercase scheme a value starts with ("http" for "HTTP://acme.com"), or null when it has none.
     */
    private static function schemeOf(string $website): ?string
    {
        return preg_match('/^([a-z][a-z0-9+.\-]*):\/\//i', $website, $matches) === 1 ? strtolower($matches[1]) : null;
    }

    /**
     * The type of the company, including a deleted one: only for display, so the type options of the forms keep hiding
     * the types that are deleted.
     *
     * @return BelongsTo<CompanyType, $this>
     */
    public function companyTypeWithTrashed(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class, 'company_type_id')->withTrashed();
    }

    /**
     * The name of the type of the company, followed by " (deleted)" when the type is deleted.
     */
    public function companyTypeLabel(): ?string
    {
        return $this->companyTypeWithTrashed?->companyTypeLabel();
    }

    /** @return Collection<int, CompanyCustomField> */
    public function customFieldDefinitions(): Collection
    {
        return CompanyCustomField::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('organization_id', $this->organization_id)
            ->orderBy('order')
            ->get();
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function companyType(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->withTimestamps();
    }
}
