<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $owner_id
 */
trait HasOwner
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The owner's name, marked when they are no longer a member of the record's organization; null when the record has no owner.
     */
    public function ownerLabel(): ?string
    {
        if ($this->owner === null) {
            return null;
        }

        return $this->owner->isMemberOf($this->organization_id)
            ? $this->owner->name
            : "{$this->owner->name} (no longer a member)";
    }
}
