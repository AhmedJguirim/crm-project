<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a failed reset-link request (unknown address, broker throttle) exactly like a
 * successful one, so the forgot-password form never reveals whether an account exists.
 */
final class PasswordResetLinkSentResponse implements FailedPasswordResetLinkRequestResponse
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        return app(SuccessfulPasswordResetLinkRequestResponse::class, ['status' => Password::RESET_LINK_SENT])
            ->toResponse($request);
    }
}
