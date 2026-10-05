<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\PreventsDeletionWhileUsedInSegments;
use App\Models\Concerns\PreventsForceDeletion;
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

    use PreventsDeletionWhileUsedInSegments;
    use PreventsForceDeletion;
    use SoftDeletes;

    protected $guarded = [];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
