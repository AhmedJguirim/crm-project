<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Inertia\Inertia;
use Inertia\Response;

class TagsController extends Controller
{
    public function index(Organization $organization): Response
    {
        return Inertia::render('Tags/Index', [
            'tags' => $organization->tags()
                ->orderBy('name')
                ->get(['id', 'name', 'color']),
        ]);
    }
}
