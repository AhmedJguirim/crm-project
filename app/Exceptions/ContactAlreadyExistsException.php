<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside the transaction of an imported row when the contact turns out to exist already, so everything written
 * for the row (such as a new company) is rolled back.
 */
class ContactAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct("A contact with email '{$email}' already exists.");
    }
}
