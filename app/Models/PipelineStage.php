<?php

namespace App\Models;

use App\Enums\DealStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory;

    /**
     * The colors a stage can have: the Filament color names the admin panel registers, with their labels.
     *
     * @var array<string, string>
     */
    public const COLORS = ['gray' => 'Gray', 'info' => 'Sky', 'primary' => 'Blue', 'success' => 'Green', 'warning' => 'Yellow', 'orange' => 'Orange', 'danger' => 'Red'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => DealStatus::class,
            'position' => 'integer',
            'probability' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
