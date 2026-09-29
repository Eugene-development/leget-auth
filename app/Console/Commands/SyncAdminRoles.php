<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Загрузочная синхронизация админов из `LEGET_ADMIN_EMAILS`.
 *
 * Это то, во что превратился allowlist после переноса ролей в БД: не проверка
 * доступа, а способ проставить роль первому админу на свежем окружении и
 * восстановить её, если роли снесли. В обработке запроса список больше не
 * участвует — авторизация читает `users.role`.
 *
 * Команда живёт в leget-auth, потому что переменная есть именно в его
 * окружении; у leget-db своя .env, и миграция бэкфилла может отработать
 * вхолостую.
 */
final class SyncAdminRoles extends Command
{
    protected $signature = 'roles:sync-admins {--prune : снять роль admin с тех, кого нет в списке}';

    protected $description = 'Проставить роль admin пользователям из LEGET_ADMIN_EMAILS (загрузочный механизм, не авторизация)';

    public function handle(): int
    {
        /** @var list<string> $emails */
        $emails = (array) config('admin.emails', []);

        if ($emails === []) {
            // Обычная ситуация на локальной машине: переменная жила только
            // в окружении контейнеров. Роль всё равно назначаема — вручную.
            $this->error('LEGET_ADMIN_EMAILS пуст — синхронизировать нечего.');
            $this->line('Назначить роль напрямую: php artisan roles:assign <email> admin');

            return self::FAILURE;
        }

        // Preserve student-only accounts before the bulk primary-role assignment.
        User::whereIn(DB::raw('LOWER(email)'), $emails)->where('role', Role::Student->value)
            ->whereNull('university_enrolled_at')->update(['university_enrolled_at' => now()]);

        $promoted = User::query()
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->where('role', '!=', Role::Superadmin->value)
            ->update(['role' => Role::Superadmin->value]);

        $this->info("Роль admin проставлена: {$promoted}.");

        $missing = array_values(array_diff($emails, $this->existingEmails($emails)));

        if ($missing !== []) {
            // Не ошибка: адрес мог быть внесён в список до регистрации.
            // Но админка для него закрыта, и знать об этом надо сразу.
            $this->warn('Нет пользователя с адресом: '.implode(', ', $missing));
        }

        if ($this->option('prune')) {
            $demoted = User::query()
                ->where('role', Role::Superadmin->value)
                ->whereNotIn(DB::raw('LOWER(email)'), $emails)
                ->update(['role' => Role::Client->value]);

            $this->info("Роль снята (--prune): {$demoted}.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function existingEmails(array $emails): array
    {
        return User::query()
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->pluck('email')
            ->map(static fn (string $email): string => strtolower($email))
            ->all();
    }
}
