<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasCustomFieldValues;
use App\Models\Concerns\PreventsForceDeletion;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Company extends Model
{
    use BelongsToOrganization;
    use HasCustomFieldValues;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use PreventsForceDeletion;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'company_type_id',
        'address_id',
        'name',
        'notes',
        'custom_field_values',
    ];

    /** @return Collection<int, CompanyCustomField> */
    public function customFieldDefinitions(): Collection
    {
        if (! $this->company_type_id) {
            return new Collection;
        }

        return CompanyCustomField::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('company_type_id', $this->company_type_id)
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
