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
        return $this->describeFor('contact', 'contacts');
    }

    /**
     * What the mode does to the records of an import, in the words of the page that offers it.
     */
    public function describeFor(string $singular, string $plural): string
    {
        return match ($this) {
            self::CreateOnly => "Adds the {$plural} that are new. A {$singular} that already exists is reported as failed.",
            self::UpdateOnly => "Updates the {$plural} that already exist. A {$singular} that is not found is reported as failed.",
            self::CreateAndUpdate => "Updates the {$plural} that exist and adds the new ones.",
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
