<?php

namespace App\Observers;

use App\Models\Tag;
use Filament\Facades\Filament;

class TagObserver
{
    public function creating(Tag $tag): void
    {
        if (! $tag->organization_id && Filament::getTenant()) {
            $tag->organization_id = Filament::getTenant()->id;
        }
    }
}
