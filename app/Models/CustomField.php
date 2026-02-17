<?php

namespace App\Models;

use App\Observers\CustomFieldObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CustomFieldObserver::class])]
class CustomField extends Model
{
    /** @use HasFactory<\Database\Factories\CustomFieldFactory> */
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
