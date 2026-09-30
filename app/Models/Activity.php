<?php

namespace App\Models;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToOrganization;
use App\Observers\ActivityObserver;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([ActivityObserver::class])]
class Activity extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected $table = 'contact_activities';

    protected $fillable = [
        'organization_id',
        'contact_id',
        'user_id',
        'type',
        'occurred_at',
        'duration_minutes',
        'subject',
        'notes',
        'outcome',
        'deal_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'outcome' => ActivityOutcome::class,
            'occurred_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
