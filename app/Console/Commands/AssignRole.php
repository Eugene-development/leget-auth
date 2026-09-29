<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Назначить роль конкретному пользователю.
 *
 * Роли задумывались назначаемыми в рантайме — без этой команды назначить их
 * было нечем: `roles:sync-admins` умеет только разобрать `LEGET_ADMIN_EMAILS`,
 * а партнёра в этом списке быть не может по определению.
 *
 * Роль не fillable и не приходит из формы: сделать человека суперадмином или
 * партнёром — административное действие, а не пользовательское.
 */
final class AssignRole extends Command
{
    protected $signature = 'roles:assign
        {email : Email пользователя}
        {role : Роль (superadmin, admin, client, student, partner, curator)}
        {--force : Снять роль superadmin с последнего суперадминистратора}';

    protected $description = 'Назначить пользователю роль (superadmin, admin, client, student, partner, curator)';

    public function handle(): int
    {
        $role = Role::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $this->error('Неизвестная роль. Доступны: '.implode(', ', array_column(Role::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->argument('email')));

        // LOWER() явно: в sqlite сравнение строк учитывает регистр, и аккаунт,
        // заведённый как Admin@…, иначе бы не нашёлся.
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user instanceof User) {
            $this->error("Пользователь {$email} не найден. Роль назначается существующему аккаунту.");

            return self::FAILURE;
        }

        if ($role === Role::Manager) {
            $this->error('Менеджер назначается администратором на конкретный сайт через CRM. Клиентский аккаунт не преобразуется.');

            return self::FAILURE;
        }
        if ($role === Role::Student) {
            if (! in_array($user->role, [Role::Client, Role::Student], true)) {
                $this->error('Для обучения используйте клиентский аккаунт.');

                return self::FAILURE;
            }
            $user->forceFill(['university_enrolled_at' => $user->university_enrolled_at ?? now()])->save();
            $this->info('Доступ студента добавлен; кабинет клиента сохранён.');

            return self::SUCCESS;
        }

        $before = $user->role ?? Role::Client;

        if ($before === $role) {
            $this->info("У {$email} уже роль {$role->value} — менять нечего.");

            return self::SUCCESS;
        }

        // Снять роль с последнего суперадмина значит запереть панель платформы:
        // восстановить её будет нечем, кроме прямого UPDATE в базе.
        if ($before === Role::Superadmin && $role !== Role::Superadmin && ! $this->option('force') && $this->superadminCount() <= 1) {
            $this->error("{$email} — последний суперадминистратор. Сначала назначьте другого или повторите с --force.");

            return self::FAILURE;
        }

        $user->forceFill(['role' => $role])->save();

        $this->info("{$email}: {$before->value} → {$role->value}");
        $this->line('Сейчас в базе: '.$this->distribution());

        return self::SUCCESS;
    }

    private function superadminCount(): int
    {
        return User::query()->where('role', Role::Superadmin->value)->count();
    }

    private function distribution(): string
    {
        $parts = [];

        foreach (Role::cases() as $role) {
            $parts[] = $role->value.'='.User::query()->where('role', $role->value)->count();
        }

        return implode(', ', $parts);
    }
}
