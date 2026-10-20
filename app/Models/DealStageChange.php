<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DealStageChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a deal into a pipeline stage (the first row of a deal has no "from" stage). Written only by
 * `DealStageMover::recordStageChange()`; a deleted stage or user leaves the column empty, the history stays.
 */
class DealStageChange extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DealStageChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'deal_id',
        'from_pipeline_stage_id',
        'to_pipeline_stage_id',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_pipeline_stage_id');
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_pipeline_stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
