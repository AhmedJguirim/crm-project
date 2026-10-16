<?php

namespace App\Exceptions;

use App\Models\PipelineStage;
use LogicException;

/**
 * Thrown when a pipeline stage cannot be deleted: it is Won or Lost, it is the last open stage, or the stage chosen to
 * receive its deals is not another open stage of the same pipeline.
 */
class PipelineStageCannotBeDeleted extends LogicException
{
    public static function notOpen(PipelineStage $stage): self
    {
        return new self('Won and Lost cannot be deleted: every pipeline keeps one of each.');
    }

    public static function lastOpenStage(PipelineStage $stage): self
    {
        return new self("The stage \"{$stage->name}\" cannot be deleted: a pipeline needs at least one open stage.");
    }

    public static function invalidTarget(): self
    {
        return new self('Choose another open stage of this pipeline to move the deals to.');
    }
}
