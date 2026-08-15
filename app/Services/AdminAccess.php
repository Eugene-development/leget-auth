<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class AdminAccess
{
    public function allows(?User $user): bool
    {
        $email = strtolower(trim((string) $user?->email));

        return $email !== '' && in_array($email, config('admin.emails', []), true);
    }
}
