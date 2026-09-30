<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Concerns\BelongsToOrganization;
use App\Observers\TaskObserver;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([TaskObserver::class])]
class Task extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'contact_id',
        'deal_id',
        'title',
        'due_at',
        'type',
        'priority',
        'notes',
        'status',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
            'type' => TaskType::class,
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    public function scopeDueThisWeek(Builder $query): Builder
    {
        return $query->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Done);
    }

    public function isOverdue(): bool
    {
        return $this->status === TaskStatus::Pending
            && filled($this->due_at)
            && $this->due_at->isPast();
    }
}
