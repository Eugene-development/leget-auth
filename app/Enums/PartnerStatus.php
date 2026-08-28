<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Состояние заявки на партнёрство.
 *
 * Заявка и профиль — одна строка (`partner_profiles`), поэтому статус описывает
 * не «стадию обработки бумаги», а состояние самого партнёрства.
 *
 * `Approved` здесь — не право. Право даёт роль `Role::Partner`, которую
 * проставляет разбор заявки. Статус и роль обязаны меняться вместе, иначе
 * одобренный партнёр останется без Офиса, а лишённый роли — «одобренным».
 */
enum PartnerStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'На рассмотрении',
            self::Approved => 'Одобрена',
            self::Rejected => 'Отклонена',
        };
    }
}
