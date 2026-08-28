<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Тип партнёра — что он делает, а не что ему разрешено.
 *
 * Права даёт роль (`Role::Partner` → способность `partner.cabinet`), тип решает
 * только, какие блоки показать в Офисе. Поэтому тип живёт в данных
 * (`partner_profiles.partner_type`), а не в карте прав: иначе каждая новая
 * специализация требовала бы правки кода доступа.
 *
 * Пока у партнёра ровно один тип. Если понадобится несколько, колонка
 * переезжает в связующую таблицу — код доступа это не затронет.
 *
 * ВНИМАНИЕ: список сведён из двух источников и требует сверки с продуктом.
 * Первые четыре названы заказчиком, последние три взяты из блока
 * «Кого мы приглашаем» на странице /partnership (Дизайнеры, Ремонтные бригады,
 * Продавцы мебели, Физ. лица). Лендинг и форма обязаны звать одних и тех же.
 *
 * Файл продублирован в leget-auth и leget-api — как и Role: общего пакета
 * между сервисами нет, а словарь обязан совпадать.
 */
enum PartnerType: string
{
    case Designer = 'designer';

    case Supplier = 'supplier';

    case Manufacturer = 'manufacturer';

    case Assembler = 'assembler';

    case RepairTeam = 'repair_team';

    case FurnitureSeller = 'furniture_seller';

    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Designer => 'Дизайнер',
            self::Supplier => 'Поставщик',
            self::Manufacturer => 'Производитель',
            self::Assembler => 'Сборщик',
            self::RepairTeam => 'Ремонтная бригада',
            self::FurnitureSeller => 'Продавец мебели',
            self::Individual => 'Физическое лицо',
        };
    }

    /**
     * Требует ли тип реквизитов компании.
     *
     * Физлицу компанию и ИНН предъявить неоткуда, и требовать их у всех значило
     * бы закрыть дверь части тех, кого зовёт лендинг.
     */
    public function isCompany(): bool
    {
        return $this !== self::Individual;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
