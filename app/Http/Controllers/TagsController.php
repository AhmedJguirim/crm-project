<?php

namespace App\Http\Controllers;

use App\Data\TagData;
use App\Models\Organization;
use App\Models\Tag;
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

    public function show(Tag $tag): Response
    {
        abort_unless(Gate::allows('view', $tag->organization), 404);

        return Inertia::render('Tags/Show', [
            'tag' => TagData::from($tag),
        ]);
    }
}
