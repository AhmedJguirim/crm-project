<?php

namespace App\Models;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasOwner;
use App\Observers\DealObserver;
use App\Support\LocalTime;
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

    use HasOwner;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'company_id',
        'title',
        'stage',
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
