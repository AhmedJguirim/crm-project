<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Observers\TagObserver;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy([TagObserver::class])]
class Tag extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'color',
    ];
}
