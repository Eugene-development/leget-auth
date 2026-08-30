<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\User;

/**
 * Роль пользователя платформы: «Суперадмин», «Клиент», «Партнёр», «Куратор».
 *
 * Роль хранится в колонке `users.role`; словарь и права — в `App\Enums\Role`.
 * Раньше роль выводилась из allowlist `LEGET_ADMIN_EMAILS`, и это не давало
 * завести третью роль без правки секрета с передеплоем трёх сервисов.
 * Allowlist понижен до загрузочного механизма: им пользуется только команда
 * `roles:sync-admins`, в обработке запроса он не участвует.
 *
 * Класс остаётся строковым фасадом над enum: константы `ADMIN`/`CLIENT` уходят
 * в JSON-ответы (`role` в профиле клиента), и менять там enum-объект на строку
 * значило бы трогать контракт leget-main заодно с переносом ролей.
 *
 * Роли взаимоисключающие: админ не может войти в клиентские маршруты
 * (`/api/client/*`), клиент — в админские (`/admin/login`).
 */
final class UserRole
{
    public const SUPERADMIN = Role::Superadmin->value;

    public const CLIENT = Role::Client->value;

    public const PARTNER = Role::Partner->value;

    public const CURATOR = Role::Curator->value;

    public function of(?User $user): string
    {
        return $this->roleOf($user)->value;
    }

    /**
     * Типизированный вариант `of()` — для проверок внутри PHP.
     *
     * Пользователь без роли в памяти (модель создана, но не перечитана из БД)
     * считается клиентом: колонка не nullable и имеет default 'client', так что
     * это ровно то, что окажется в базе.
     */
    public function roleOf(?User $user): Role
    {
        return $user?->role ?? Role::Client;
    }

    public function isSuperadmin(?User $user): bool
    {
        return $this->roleOf($user) === Role::Superadmin;
    }

    public function isClient(?User $user): bool
    {
        return $this->roleOf($user) === Role::Client;
    }

    /**
     * Проверка по адресу — нужна до того, как пользователь аутентифицирован
     * (регистрация клиента и вход клиента по чужому email).
     *
     * ВНИМАНИЕ, смена поведения: раньше отказ получал любой адрес из allowlist,
     * даже если такого пользователя в базе не было. Теперь отказ получает
     * только адрес существующего админа. Занять чужой адрес это не позволяет —
     * `email` остаётся уникальным, — но «зарезервировать» адрес будущего
     * админа заранее больше нельзя: сначала пользователь, потом роль.
     */
    public function isSuperadminEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        // LOWER() явно: sqlite в тестах сравнивает строки с учётом регистра,
        // и админ, заведённый как Admin@…, иначе прошёл бы проверку.
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('role', Role::Superadmin->value)
            ->exists();
    }
}
