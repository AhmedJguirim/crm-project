<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside the transaction of a segment sync when the published rules were replaced (or the segment unpublished)
 * after they were read: it rolls the sync back so that a fresh one, with the new rules, can run.
 */
class RulesChangedDuringSync extends RuntimeException {}
