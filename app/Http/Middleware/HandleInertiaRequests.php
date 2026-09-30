<?php

namespace App\Http\Middleware;

use App\Data\InertiaSharedData;
use App\Data\OrganizationData;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'inertia';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $sharedData = new InertiaSharedData(
            currentOrganization: $this->currentOrganization($request),
            organizations: OrganizationData::collect(
                $request->user()?->organizations()->orderBy('name')->get() ?? [],
                'array',
            ),
        );

        return [
            ...parent::share($request),
            ...$sharedData->toArray(),
        ];
    }

    /**
     * Resolve the organization from the route: either bound directly
     * (e.g. /app/{organization:slug}/tags) or through a bound record
     * that belongs to one (e.g. /app/tags/{tag}).
     */
    private function currentOrganization(Request $request): ?OrganizationData
    {
        $organization = collect($request->route()?->parameters() ?? [])
            ->filter(fn (mixed $parameter): bool => $parameter instanceof Model)
            ->map(fn (Model $record): ?Organization => $record instanceof Organization
                ? $record
                : ($record->isRelation('organization') ? $record->organization : null))
            ->filter()
            ->first();

        return $organization ? OrganizationData::from($organization) : null;
    }
}
