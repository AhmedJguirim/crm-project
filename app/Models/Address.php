<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
