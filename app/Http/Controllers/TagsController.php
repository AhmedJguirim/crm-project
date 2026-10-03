<?php

namespace App\Http\Controllers;

use App\Data\OrganizationData;
use App\Data\TagData;
use App\Models\Organization;
use App\Models\Tag;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TagsController extends Controller
{
    public function index(Organization $organization): Response
    {
        return Inertia::render('Tags/Index', [
            'tags' => TagData::collect($organization->tags()->orderBy('name')->get()),
        ]);
    }

    public function show(string $tag): Response
    {
        $tag = Tag::withoutGlobalScope('organization')->findOrFail($tag);

        abort_unless(Gate::allows('view', $tag->organization), 404);

        Inertia::share('currentOrganization', OrganizationData::from($tag->organization)->toArray());

        return app(TenantContext::class)->run($tag->organization_id, fn (): Response => Inertia::render('Tags/Show', [
            'tag' => TagData::from($tag),
        ]));
    }
}
