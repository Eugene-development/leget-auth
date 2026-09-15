<?php

return [
    'recipient' => env('ADMIN_EMAIL', 'info@leget.ru'),
    // Optional server-only override, useful on staging. Never accepted from a form.
    'recipient_override' => env('FORM_RECIPIENT_OVERRIDE'),
    'attachment_disk' => env('FORM_ATTACHMENT_DISK', 'yandex'),
    'max_attempts' => 8,
    'types' => [
        'consultation' => 'Консультация',
        'design-project' => 'Дизайн-проект',
        'furniture-project' => 'Проектирование мебели',
        'assembly' => 'Сборка и монтаж',
        'measurement' => 'Замер помещения',
        'installment' => 'Рассрочка',
        'partnership' => 'Сотрудничество',
        'promo' => 'Промокод',
        'vacancy' => 'Отклик на вакансию',
        'careers' => 'Отклик на вакансию',
        'warranty' => 'Гарантийное обращение',
        'contact' => 'Обратная связь',
        'subscription' => 'Подписка',
        'partner-application' => 'Заявка партнёра платформы',
    ],
    'conversion_types' => ['consultation', 'design-project', 'furniture-project', 'assembly', 'measurement', 'installment', 'partnership', 'promo', 'contact'],
];
