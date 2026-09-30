<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\IsCustomField;
use App\Observers\CompanyCustomFieldObserver;
use Database\Factories\CompanyCustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CompanyCustomFieldObserver::class])]
class CompanyCustomField extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CompanyCustomFieldFactory> */
    use HasFactory;

    use IsCustomField;

    protected $fillable = [
        'organization_id',
        'company_type_id',
        'name',
        'type',
        'options',
        'unique',
        'order',
    ];

    /** @return array{company_type_id: int|null} */
    public function keyUniquenessScope(): array
    {
        return ['company_type_id' => $this->company_type_id];
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unique' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function companyType(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class);
    }
}
