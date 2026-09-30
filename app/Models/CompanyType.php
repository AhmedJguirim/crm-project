<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CompanyTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyType extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CompanyTypeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function customFields(): HasMany
    {
        return $this->hasMany(CompanyCustomField::class)->orderBy('order');
    }
}
