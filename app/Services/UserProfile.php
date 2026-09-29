<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Профиль пользователя платформы в том виде, в каком его ждёт leget-main.
 *
 * Вынесен из ClientAuthController, потому что его отдают несколько мест: ответы
 * `register` и `login` и маршрут `/api/session/me`. Две копии массива разошлись бы
 * при первом же новом поле, и роль в одном ответе отличалась бы от роли в другом.
 */
final class UserProfile
{
    public function __construct(private readonly UserRole $roles) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'region' => $user->region,
            'email_verified' => $user->hasVerifiedEmail(),
            'role' => $this->roles->of($user),
            'roles' => $user->roleNames(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
