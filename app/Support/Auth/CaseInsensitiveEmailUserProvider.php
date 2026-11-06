<?php

namespace App\Support\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds a user by email whatever the case of the stored or the typed address, so a row stored with capitals before
 * addresses were lower-cased can still log in and reset its password (BUG-65). When two rows differ only by case, the
 * exact-case row wins, then the lowest id; the password check then decides.
 */
final class CaseInsensitiveEmailUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return (Authenticatable&Model)|null
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $email = $credentials['email'] ?? null;

        if (is_string($email)) {
            $credentials['email'] = fn (Builder $query): Builder => $query
                ->whereEmailIgnoringCase($email)
                ->orderByRaw('users.email = ? desc', [$email])
                ->orderBy('users.id');
        }

        return parent::retrieveByCredentials($credentials);
    }
}
