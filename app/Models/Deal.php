<?php

namespace App\Models;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    /** @use HasFactory<\Database\Factories\DealFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'title',
        'stage',
        'value',
        'currency',
        'expected_close_date',
        'notes',
        'status',
        'won_at',
        'lost_at',
        'created_by',
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

    protected static function booted(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $tenantId = Filament::getTenant()?->id;

            if ($tenantId) {
                $builder->where('organization_id', $tenantId);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
