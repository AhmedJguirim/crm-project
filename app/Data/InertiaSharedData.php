<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Props shared with every Inertia page by HandleInertiaRequests.
 */
#[TypeScript]
class InertiaSharedData extends Data
{
    /**
     * @param  array<int, OrganizationData>  $organizations
     */
    public function __construct(
        public ?OrganizationData $currentOrganization,
        #[DataCollectionOf(OrganizationData::class)]
        public array $organizations,
    ) {}
}
