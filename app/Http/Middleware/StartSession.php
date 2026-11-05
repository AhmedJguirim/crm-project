<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;

/**
 * Never records the invitation link as the previous URL: its path holds the plain invite token (BUG-62).
 */
class StartSession extends FrameworkStartSession
{
    /**
     * @param  Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session): void
    {
        if ($request->route() instanceof Route && $request->route()->named('invite.accept')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
