<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\PreventsForceDeletion;
use Database\Factories\LostReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LostReason extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<LostReasonFactory> */
    use HasFactory;

    use PreventsForceDeletion;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * The name, followed by " (deleted)" when the reason is deleted.
     */
    public function lostReasonLabel(): string
    {
        return $this->name.($this->trashed() ? ' (deleted)' : '');
    }
}
