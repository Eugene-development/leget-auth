<?php

declare(strict_types=1);

namespace App\Auth;

use Tymon\JWTAuth\JWTGuard;

/** Every password reset invalidates the account's previously issued JWTs. */
final class VersionedJwtGuard extends JWTGuard
{
    public function user()
    {
        // Preserve explicitly authenticated users (credential login / actingAs).
        if ($this->user !== null) {
            return $this->user;
        }

        $user = parent::user();
        if ($user === null) {
            return null;
        }

        $claims = $this->payload()->toArray();
        $version = array_key_exists('token_version', $claims) ? $claims['token_version'] : 0;

        if (! is_int($version) || $version < 0 || $version !== (int) $user->token_version) {
            $this->forgetUser();

            return null;
        }

        return $user;
    }
}
