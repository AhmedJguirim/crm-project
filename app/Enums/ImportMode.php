<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum ImportMode: string implements HasDescription, HasLabel
{
    case CreateOnly = 'create';
    case UpdateOnly = 'update';
    case CreateAndUpdate = 'create_and_update';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::CreateOnly => 'Create only',
            self::UpdateOnly => 'Update only',
            self::CreateAndUpdate => 'Create and update',
        };
    }

    public function getDescription(): string|Htmlable|null
    {
        return match ($this) {
            self::CreateOnly => 'Adds the contacts that are new. A contact that already exists is reported as failed.',
            self::UpdateOnly => 'Updates the contacts that already exist. A contact that is not found is reported as failed.',
            self::CreateAndUpdate => 'Updates the contacts that exist and adds the new ones.',
        };
    }

    public function updatesExisting(): bool
    {
        return $this !== self::CreateOnly;
    }

    public function createsNew(): bool
    {
        return $this !== self::UpdateOnly;
    }

    /**
     * The mode of a submitted form: Filament gives the case when the field was left on its default and the value
     * otherwise.
     */
    public static function fromState(self|string $state): self
    {
        return $state instanceof self ? $state : self::from($state);
    }
}
