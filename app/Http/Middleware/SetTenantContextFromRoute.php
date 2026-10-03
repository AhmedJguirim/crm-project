<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context from the bound `{organization}` of the route, for the rest of the request. It must run
 * after the membership check.
 */
class SetTenantContextFromRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->route('organization');

        if ($organization instanceof Organization) {
            app(TenantContext::class)->set($organization->getKey());
        }

        return $next($request);
    }
}
