<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\MasterAdmin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Hash;

class AdminUserProvider implements UserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     *
     * @param mixed $identifier
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        if ($identifier === 'master') {
            return MasterAdmin::instance();
        }

        if (is_numeric($identifier)) {
            return User::find((int) $identifier);
        }

        return null;
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param mixed $identifier
     * @param string $token
     */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param string $token
     */
    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // Not applicable for master admin
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (! isset($credentials['password'], $credentials['username'])) {
            return null;
        }

        $username = (string) $credentials['username'];

        if ($username === 'master') {
            return MasterAdmin::instance();
        }

        return User::query()
            ->where('username', $username)
            ->first();
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        if (! isset($credentials['password'])) {
            return false;
        }

        if ($user instanceof MasterAdmin) {
            return $user->validatePassword($credentials['password']);
        }

        if ($user instanceof User) {
            return Hash::check((string) $credentials['password'], $user->password);
        }

        return false;
    }

    /**
     * Rehash the user's password if required and supported.
     *
     * @param array<string, mixed> $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Not applicable for master admin
    }
}
