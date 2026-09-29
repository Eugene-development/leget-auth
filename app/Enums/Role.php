<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Основная роль платформы. Студент добавляется через university_enrolled_at:
 * клиент сохраняет свою роль и доступ к покупательскому кабинету.
 * Admin — администратор сайта; Superadmin — сотрудник платформы.
 * Роль Admin не открывает чужие сайты: владение проверяется по licenses.user_id.
 * Student оставлен для совместимости с аккаунтами до миграции.
 * Словарь и способности синхронизированы в leget-auth и leget-api.
 */
enum Role: string
{
    case Superadmin = 'superadmin';

    case Client = 'client';

    case Admin = 'admin';

    case Manager = 'manager';

    case Student = 'student';

    case Partner = 'partner';

    /**
     * Куратор — сотрудник платформы, который ведёт клиента от рекламного
     * перехода до сделки: создаёт и активирует промокод, назначает партнёра,
     * сопровождает сделку и может внести сведения от имени партнёра.
     *
     * Куратор получает переменное вознаграждение за закрытые сделки, поэтому
     * его способности намеренно не включают `promo.confirm`: подтвердить
     * заявленную им же сделку он не может — это делает клиент или админ
     * с основанием. Разделение живёт в карте прав, а не в проверке внутри
     * сервиса, чтобы его нельзя было обойти новым маршрутом.
     */
    case Curator = 'curator';

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
            self::Admin => ['site.manage'],
            self::Manager => ['crm.work'],
            self::Student => ['university.study'],
            self::Superadmin => [
                'university.manage',
                'users.curate',
                'crm.admin',
                'conversions.view',
                'conversions.record',
                'clients.view',
                'partners.review',
                // Промокоды: админ видит весь реестр и разрешает споры.
                // `promo.confirm` — административное подтверждение сделки без
                // ответа клиента, обязательно с основанием и записью в журнал.
                'promo.admin',
                'promo.confirm',
            ],
            self::Partner => [
                // Ворота в Офис партнёра (`/partner` в leget-main). Что именно
                // он там видит, определяет не способность, а тип партнёра
                // в `partner_profiles`: тип — данные, а не право.
                'partner.cabinet',
                // Промокоды, назначенные организации партнёра: отметить
                // предъявление, оформить заказ, заявить сделку. Окончательно
                // подтвердить собственную сделку партнёр не может — для этого
                // нужна `promo.confirm`, которой у роли нет.
                'promo.partner',
            ],
            self::Curator => [
                // Ворота в кабинет куратора и все действия по сопровождению.
                // `promo.confirm` здесь нет намеренно: см. комментарий у case.
                'promo.curate',
            ],
            self::Client => [
                // Маршрута за способностью сейчас нет: профиль отдаёт открытый
                // `/api/session/me`, а страница `/cabinet` в leget-main сверяет
                // роль сама. Способность объявлена под клиентские данные —
                // заказы и документы, — которые появятся позже. Пока её держит
                // только тест на карту прав, и это осознанно.
                'cabinet.view',
                // Свои промокоды: посмотреть, подтвердить или оспорить
                // заявленную сделку. Чужие не покажет ни один запрос —
                // выборка всегда сужена до `client_id` вошедшего.
                'promo.client',
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
