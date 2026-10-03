<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a save would give a record a value another record already holds in a unique custom field.
 */
class DuplicateCustomFieldValueException extends RuntimeException
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $fieldKey,
    ) {
        parent::__construct("The {$fieldName} must be unique.");
    }
}
