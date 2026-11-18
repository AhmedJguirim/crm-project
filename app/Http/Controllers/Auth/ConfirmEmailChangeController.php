<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\EmailChanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConfirmEmailChangeController extends Controller
{
    /**
     * Swaps in the pending email address when the signed-in user opens the link mailed to it (SEC-56).
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        abort_unless(hash_equals((string) $user->getKey(), $id), 403);

        $refusal = EmailChanges::confirm($user, $hash);

        if ($refusal !== null) {
            return redirect()->route('profile.edit')->with('email-change-error', $refusal);
        }

        return redirect()->route('profile.edit')
            ->with('email-change-status', 'Your email address is now '.$user->fresh()->email.'.');
    }
}
