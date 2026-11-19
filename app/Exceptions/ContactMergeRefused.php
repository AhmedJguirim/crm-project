<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the contact merger when a merge is refused (same contact twice, other organization, role, forged choice, a
 * contact deleted or merged in the meantime). The message is meant for the user.
 */
class ContactMergeRefused extends RuntimeException {}
