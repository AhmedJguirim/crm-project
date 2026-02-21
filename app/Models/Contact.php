<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Observers\ContactObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([ContactObserver::class])]
class Contact extends Model
{
    /** @use HasFactory<\Database\Factories\ContactFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'status',
        'phone',
        'lead_source',
        'custom_field_values',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'lead_source' => LeadSource::class,
            'custom_field_values' => 'array',
        ];
    }

    public function scopeActiveClients(Builder $query): Builder
    {
        return $query->where('status', ContactStatus::ActiveClient->value);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
