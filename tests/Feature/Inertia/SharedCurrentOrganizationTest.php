<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organization;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

function sharedCurrentOrganizationFor(mixed ...$parameters): mixed
{
    $request = Request::create('/app/x');
    $route = new Route('GET', '/app/x', fn () => null);
    $route->parameters = $parameters;
    $request->setRouteResolver(fn (): Route => $route);

    return (new HandleInertiaRequests)->share($request)['currentOrganization'];
}

test('the shared organization comes from a bound organization', function () {
    $organization = Organization::factory()->create();

    expect(sharedCurrentOrganizationFor($organization)['slug'])->toBe($organization->slug);
});

test('the shared organization comes from the organization of a bound record', function () {
    $organization = Organization::factory()->create();
    $tag = Tag::factory()->for($organization)->create();

    expect(sharedCurrentOrganizationFor($tag)['slug'])->toBe($organization->slug);
});

test('the shared organization is null without a bound model', function () {
    expect(sharedCurrentOrganizationFor('plain'))->toBeNull();
});
