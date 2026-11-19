<?php

namespace App\Enums;

/**
 * Which of the two contacts of a merge a field takes its value from.
 */
enum MergeSide: string
{
    case Survivor = 'survivor';
    case Loser = 'loser';
}
