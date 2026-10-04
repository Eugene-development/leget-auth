<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Encryption\Encrypter;

/** Authenticate the email before an account lookup or expensive broker hash check. */
final class PasswordRecoveryToken
{
    public function __construct(private Encrypter $encrypter) {}

    public function sign(string $email, #[\SensitiveParameter] string $token): string
    {
        return $token.'.'.$this->signature($email, $token, $this->encrypter->getKey());
    }

    public function verify(string $email, #[\SensitiveParameter] string $envelope): ?string
    {
        if (preg_match('/\A([a-f0-9]{64})\.([a-f0-9]{64})\z/', $envelope, $parts) !== 1) {
            return null;
        }

        $valid = false;
        // Evaluate every configured key, including during application key rotation.
        foreach ($this->encrypter->getAllKeys() as $key) {
            $valid = hash_equals($this->signature($email, $parts[1], $key), $parts[2]) || $valid;
        }

        return $valid ? $parts[1] : null;
    }

    private function signature(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', "leget-password-recovery:v1\0".strtolower(trim($email))."\0".$token, $key);
    }
}
