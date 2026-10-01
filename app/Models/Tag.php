<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\PreventsDeletionWhileUsedInSegments;
use App\Models\Concerns\PreventsForceDeletion;
use App\Observers\TagObserver;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([TagObserver::class])]
class Tag extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use PreventsDeletionWhileUsedInSegments;
    use PreventsForceDeletion;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'color',
    ];
}
