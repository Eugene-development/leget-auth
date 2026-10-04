<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;

/** The same session lifetime applies to every login method and role. */
final class LoginSession
{
    public function attempt(array $credentials, bool $remember): array|false
    {
        return $this->issue(fn () => JWTAuth::attempt($credentials), $remember);
    }

    public function forUser(User $user, bool $remember = false): array
    {
        return $this->issue(fn () => JWTAuth::fromUser($user), $remember);
    }

    /** Updating account roles must preserve the original login's lifetime. */
    public function reissue(User $user, ?string $token): array
    {
        if ($token === null) {
            return $this->forUser($user);
        }

        $payload = JWTAuth::setToken($token)->getPayload();
        abort_unless((string) $payload->get('sub') === (string) $user->id, 401);

        return $this->issue(
            fn () => JWTAuth::fromUser($user),
            $payload->get('remember') === true,
            (int) $payload->get('exp'),
        );
    }

    private function issue(Closure $createToken, bool $remember, ?int $expiresAt = null): array|false
    {
        $factory = JWTAuth::factory();
        $previousTtl = $factory->getTTL();
        $previousClaims = JWTAuth::getCustomClaims();
        $previousFactoryClaims = $factory->getCustomClaims();
        $ttl = max(1, (int) config($remember ? 'jwt.remember_ttl' : 'jwt.ttl', $remember ? 43200 : 60));
        $expiresAt ??= now()->timestamp + $ttl * 60;

        try {
            $factory->emptyClaims()->setTTL($ttl);
            JWTAuth::claims([...$previousClaims, 'remember' => $remember, 'exp' => $expiresAt]);
            $token = $createToken();
            if ($token === false) {
                return false;
            }

            return [
                'token' => $token,
                'expires_in' => max(0, $expiresAt - now()->timestamp),
                'remember' => $remember,
            ];
        } finally {
            // JWT services are shared: a remembered login must not affect the next one.
            JWTAuth::claims($previousClaims);
            $factory->emptyClaims()->customClaims($previousFactoryClaims)->setTTL($previousTtl);
        }
    }
}
