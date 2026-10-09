<?php

namespace App\Models;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Observers\DealObserver;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([DealObserver::class])]
class Deal extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DealFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'title',
        'stage',
        'value',
        'expected_close_date',
        'notes',
        'status',
        'won_at',
        'lost_at',
        'created_by',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'stage' => DealStage::class,
            'status' => DealStatus::class,
            'value' => 'decimal:2',
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DealStatus::Open->value);
    }

    public function scopeWon(Builder $query): Builder
    {
        return $query->where('status', DealStatus::Won->value);
    }

    public function scopeLost(Builder $query): Builder
    {
        return $query->where('status', DealStatus::Lost->value);
    }

    public function scopeByStage(Builder $query, DealStage $stage): Builder
    {
        return $query->where('stage', $stage->value);
    }

    public function scopeClosingThisWeek(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expected_close_date')
            ->whereBetween('expected_close_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    /**
     * An open deal whose expected close date is strictly before today (a deal closing today is not late yet).
     */
    public function isOverdue(): bool
    {
        return $this->status === DealStatus::Open
            && $this->expected_close_date !== null
            && $this->expected_close_date->lt(today());
    }
}
