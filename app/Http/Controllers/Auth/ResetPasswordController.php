<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\NewPasswordController;

class ResetPasswordController extends NewPasswordController
{
    /**
     * Reset the user's password. The parent lower-cases the email before it validates it, so a forged
     * non-string email (an array) crashed it with a TypeError; it is refused here with a validation error (BUG-66).
     *
     * @throws ValidationException
     */
    public function store(Request $request): Responsable
    {
        $email = $request->input(Fortify::email());

        if ($email !== null && ! is_string($email)) {
            throw ValidationException::withMessages([
                Fortify::email() => __('validation.string', ['attribute' => Fortify::email()]),
            ]);
        }

        return parent::store($request);
    }
}
