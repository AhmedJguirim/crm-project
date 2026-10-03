<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\InviteAcceptController;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use SensitiveParameter;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response) {
            /** @var User|null $user */
            $user = Filament::auth()->user();

            if ($user && ! $user->onboarding_completed) {
                session(['show_onboarding' => true]);
            }

            $this->acceptPendingInviteIfExists($user);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $email = $data['email'];

        if (config('fortify.lowercase_usernames')) {
            $email = Str::lower($email);
        }

        return [
            'email' => $email,
            'password' => $data['password'],
        ];
    }

    private function acceptPendingInviteIfExists(User $user): void
    {
        $token = session()->pull('invite_token');
        session()->forget('invite_email');

        if (! $token) {
            return;
        }

        $invite = OrganizationInvite::where('token', $token)->first();

        // Only accept if the invite is still valid and the email matches
        if (! $invite || ! $invite->isValid() || $invite->email !== $user->email) {
            return;
        }

        /** @var InviteAcceptController $controller */
        $controller = app(InviteAcceptController::class);
        $controller->acceptInvite($invite, $user);
    }
}
