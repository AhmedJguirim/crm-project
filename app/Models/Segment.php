<?php

namespace App\Models;

use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Segments\SegmentFieldCatalog;
use App\Services\Segments\SegmentQueryBuilder;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A dynamic group of contacts defined by rules (OR) of conditions (AND).
 *
 * `rules` holds the published definition used to compute membership (stored in `contact_segment`).
 * Once published, edits go to `draft_rules` until they are saved (copied to `rules`) or cancelled.
 */
class Segment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'rules',
        'draft_rules',
        'is_published',
        'is_syncing',
        'last_synced_at',
    ];

    protected $attributes = [
        'rules' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'draft_rules' => 'array',
            'is_published' => 'boolean',
            'is_syncing' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->withTimestamps();
    }

    /**
     * The published definition.
     *
     * @return Collection<int, SegmentRuleData>
     */
    public function publishedRules(): Collection
    {
        return collect(SegmentRuleData::collect($this->rules ?? []));
    }

    /**
     * The definition being edited: the draft when there is one, the published definition otherwise.
     *
     * @return Collection<int, SegmentRuleData>
     */
    public function workingRules(): Collection
    {
        return collect(SegmentRuleData::collect($this->draft_rules ?? $this->rules ?? []));
    }

    /**
     * Store an edited definition: directly while unpublished, as a draft once published.
     *
     * @param  iterable<SegmentRuleData>  $rules
     */
    public function storeWorkingRules(iterable $rules): void
    {
        $serialized = collect($rules)->map(fn (SegmentRuleData $rule): array => $rule->toArray())->values()->all();

        if ($this->is_published) {
            $this->draft_rules = $serialized;
        } else {
            $this->rules = $serialized;
        }

        $this->save();
    }

    public function status(): SegmentStatus
    {
        return match (true) {
            $this->is_syncing => SegmentStatus::Syncing,
            ! $this->is_published => SegmentStatus::Draft,
            $this->hasPendingChanges() => SegmentStatus::PendingChanges,
            default => SegmentStatus::Published,
        };
    }

    public function hasPendingChanges(): bool
    {
        return $this->draft_rules !== null && $this->draft_rules != $this->rules;
    }

    public function discardDraft(): void
    {
        $this->update(['draft_rules' => null]);
    }

    public function canBePublished(): bool
    {
        return ! $this->is_syncing && $this->fieldCatalog()->areRulesComplete($this->workingRules());
    }

    /**
     * Make the working definition the published one and flag the segment for a membership sync.
     */
    public function publishWorkingRules(): void
    {
        $this->update([
            'rules' => $this->draft_rules ?? $this->rules,
            'draft_rules' => null,
            'is_published' => true,
            'is_syncing' => true,
        ]);
    }

    public function fieldCatalog(): SegmentFieldCatalog
    {
        return SegmentFieldCatalog::forOrganization($this->organization_id);
    }

    public function queryBuilder(): SegmentQueryBuilder
    {
        return new SegmentQueryBuilder($this->fieldCatalog());
    }
}
