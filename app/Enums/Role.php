<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Роль пользователя платформы.
 *
 * Разделение, на котором держится вся схема доступа:
 * **назначение роли — данные** (колонка `users.role`), **права роли — код**
 * (карта `abilities()` ниже). Первое меняется в рантайме без деплоя, второе
 * не может меняться без него в принципе: маршрут `/partner/*` не появится
 * от строки в таблице, поэтому таблица прав была бы вторым источником правды.
 *
 * Роли взаимоисключающие: у пользователя ровно одна.
 *
 * ВНИМАНИЕ, именование. `Superadmin` — это сотрудник LEGET с панелью `/admin`
 * (конверсии, «Мои клиенты»). Тот, кого в продукте называют «админом сайта» —
 * это **владелец сайта**, и ролью он не является: право править контент даёт
 * владение лицензией (`licenses.user_id`, проверка в UpsertPageComponent).
 * В колонку это не переносится: роль глобальна, а владелец — владелец
 * конкретного сайта; на чужом он обычный посетитель.
 *
 * Файл намеренно продублирован в leget-auth и leget-api: общего пакета между
 * сервисами нет, а словарь ролей обязан совпадать. Так же дублировался
 * config/admin.php, который эта схема заменяет.
 */
enum Role: string
{
    case Superadmin = 'superadmin';

    case Client = 'client';

    case Partner = 'partner';

    /**
     * Способности роли — то, что проверяют маршруты через `can:`.
     *
     * Проверять надо способность, а не имя роли: когда доступ к разделу
     * получит вторая роль, здесь добавится строка, а искать по коду
     * `role === 'partner'` не придётся.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Superadmin => [
                'conversions.view',
                'conversions.record',
                'clients.view',
                'partners.review',
            ],
            self::Partner => [
                // Ворота в Офис партнёра (`/partner` в leget-main). Что именно
                // он там видит, определяет не способность, а тип партнёра
                // в `partner_profiles`: тип — данные, а не право.
                'partner.cabinet',
            ],
            self::Client => [
                // Маршрута за способностью сейчас нет: профиль отдаёт открытый
                // `/api/session/me`, а страница `/cabinet` в leget-main сверяет
                // роль сама. Способность объявлена под клиентские данные —
                // заказы и документы, — которые появятся позже. Пока её держит
                // только тест на карту прав, и это осознанно.
                'cabinet.view',
            ],
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /**
     * Все объявленные способности — по ним AppServiceProvider регистрирует Gate.
     *
     * @return list<string>
     */
    public static function abilityNames(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(static fn (self $role): array => $role->abilities(), self::cases())
        )));
    }
}
