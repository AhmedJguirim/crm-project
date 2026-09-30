<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'custom_field_values' => 'array',
        ];
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function companyType(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class);
    }
}
