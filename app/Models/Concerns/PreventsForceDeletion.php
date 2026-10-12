<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Records of models using this trait can only be soft-deleted: permanently deleting them is refused.
 */
trait PreventsForceDeletion
{
    public static function bootPreventsForceDeletion(): void
    {
        static::forceDeleting(function (self $model): never {
            throw new LogicException(class_basename($model).' records cannot be permanently deleted. Soft-delete them instead.');
        });
    }
}
