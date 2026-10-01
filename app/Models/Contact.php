<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasCustomFieldValues;
use App\Models\Concerns\PreventsForceDeletion;
use App\Observers\ContactObserver;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[ObservedBy([ContactObserver::class])]
class Contact extends Model
{
    use BelongsToOrganization;
    use HasCustomFieldValues;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use PreventsForceDeletion;
    use SoftDeletes;

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
        ];
    }

    public function scopeActiveClients(Builder $query): Builder
    {
        return $query->where('status', ContactStatus::ActiveClient->value);
    }

    /** @return Collection<int, CustomField> */
    public function customFieldDefinitions(): Collection
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->withoutTrashed()
            ->where('organization_id', $this->organization_id)
            ->orderBy('order')
            ->get();
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag');
    }

    /**
     * Replace the contact's tags, keeping its trashed tags attached so they come back if the tag is restored.
     *
     * @param  array<int, int|string>  $tagIds
     */
    public function syncTags(array $tagIds): void
    {
        $trashedTagIds = $this->tags()->onlyTrashed()->pluck('tags.id')->all();

        $this->tags()->sync(array_values(array_unique([...array_map('intval', $tagIds), ...$trashedTagIds])));
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class)->withTimestamps();
    }
}
