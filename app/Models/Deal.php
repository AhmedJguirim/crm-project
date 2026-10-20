<?php

namespace App\Models;

use App\Enums\DealStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasOwner;
use App\Observers\DealObserver;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy([DealObserver::class])]
class Deal extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use HasOwner;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'company_id',
        'title',
        'pipeline_stage_id',
        'value',
        'expected_close_date',
        'notes',
        'status',
        'won_at',
        'lost_at',
        'created_by',
        'owner_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'value' => 'decimal:2',
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The company of the deal, only while it is live.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The company of the deal, including a deleted one: only for display, so the options of the forms keep hiding the
     * companies that are deleted.
     *
     * @return BelongsTo<Company, $this>
     */
    public function companyWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')->withTrashed();
    }

    /**
     * The name of the company of the deal, followed by " (deleted)" when the company is deleted; null without a company.
     */
    public function companyLabel(): ?string
    {
        $company = $this->companyWithTrashed;

        if ($company === null) {
            return null;
        }

        return $company->name.($company->trashed() ? ' (deleted)' : '');
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<DealStageChange, $this>
     */
    public function stageChanges(): HasMany
    {
        return $this->hasMany(DealStageChange::class);
    }

    /**
     * The newest stage history row (latest changed_at, then highest id), loadable for many deals in one query.
     *
     * @return HasOne<DealStageChange, $this>
     */
    public function latestStageChange(): HasOne
    {
        return $this->hasOne(DealStageChange::class)->latestOfMany('changed_at');
    }

    /**
     * When the deal entered its current stage, from its stage history; null when the history doesn't know (no row, or
     * the latest row is for another stage). Uses the eager-loaded `latestStageChange` when present, so lists pay one
     * query for all their deals.
     */
    public function stageEnteredAt(): ?CarbonInterface
    {
        $latest = $this->relationLoaded('latestStageChange') ? $this->latestStageChange : $this->latestStageChange()->first();

        if ($latest === null || (int) $latest->to_pipeline_stage_id !== (int) $this->pipeline_stage_id) {
            return null;
        }

        return $latest->changed_at;
    }

    /**
     * How long the deal has been in its current stage (e.g. "3 days"); null when the stage history doesn't know.
     */
    public function timeInStageForHumans(): ?string
    {
        return $this->stageEnteredAt()?->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
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

    public function scopeClosingThisWeek(Builder $query): Builder
    {
        return $query
            ->whereNotNull('expected_close_date')
            ->whereBetween('expected_close_date', LocalTime::periodDates('week'));
    }

    /**
     * An open deal whose expected close date is strictly before today in the organization timezone (a deal closing
     * today is not late yet).
     */
    public function isOverdue(): bool
    {
        return $this->status === DealStatus::Open
            && $this->expected_close_date !== null
            && $this->expected_close_date->toDateString() < LocalTime::todayDate();
    }
}
