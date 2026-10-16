<?php

namespace App\Filament\Resources\PipelineStages\Pages;

use App\Filament\Resources\PipelineStages\PipelineStageResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Resources\Pages\ManageRecords;

class ManagePipelineStages extends ManageRecords
{
    use SanitizesTableSearch;

    protected static string $resource = PipelineStageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
