<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Thrown when code tries to change the type of an existing custom field: stored values are not converted, so they
 * would be silently lost or corrupted. Another type means a new field.
 */
class CustomFieldTypeLockedException extends LogicException
{
    public static function for(Model $field): self
    {
        return new self("The type of custom field \"{$field->getAttribute('name')}\" can't change after it is created.");
    }
}
