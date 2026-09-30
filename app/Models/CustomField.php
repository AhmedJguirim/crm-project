<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Observers\CustomFieldObserver;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy([CustomFieldObserver::class])]
class CustomField extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'options',
        'unique',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'unique' => 'boolean',
            'order' => 'integer',
        ];
    }
}
