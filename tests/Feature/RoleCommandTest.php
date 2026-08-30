<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_assigned_to_an_existing_user(): void
    {
        $this->user('ivan@example.test');

        $this->artisan('roles:assign', ['email' => 'IVAN@example.test', 'role' => 'partner'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'ivan@example.test',
            'role' => Role::Partner->value,
        ]);
    }

    /**
     * Куратор — сотрудник платформы, и роль ему назначают той же командой,
     * что и партнёру. Форма регистрации её не выдаёт.
     */
    public function test_curator_role_is_assignable(): void
    {
        $this->user('kate@example.test');

        $this->artisan('roles:assign', ['email' => 'kate@example.test', 'role' => 'curator'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'kate@example.test',
            'role' => Role::Curator->value,
        ]);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->user('ivan@example.test');

        $this->artisan('roles:assign', ['email' => 'ivan@example.test', 'role' => 'wizard'])
            ->assertFailed();

        $this->assertDatabaseHas('users', [
            'email' => 'ivan@example.test',
            'role' => Role::Client->value,
        ]);
    }

    public function test_missing_user_is_rejected(): void
    {
        $this->artisan('roles:assign', ['email' => 'nobody@example.test', 'role' => 'superadmin'])
            ->assertFailed();
    }

    /**
     * Снять роль с последнего суперадмина значит запереть панель платформы:
     * восстановить её будет нечем, кроме прямого UPDATE в базе.
     */
    public function test_last_superadmin_cannot_be_demoted_without_force(): void
    {
        $this->user('admin@example.test', Role::Superadmin);

        $this->artisan('roles:assign', ['email' => 'admin@example.test', 'role' => 'client'])
            ->assertFailed();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.test',
            'role' => Role::Superadmin->value,
        ]);
    }

    public function test_last_superadmin_can_be_demoted_with_force(): void
    {
        $this->user('admin@example.test', Role::Superadmin);

        $this->artisan('roles:assign', ['email' => 'admin@example.test', 'role' => 'client', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.test',
            'role' => Role::Client->value,
        ]);
    }

    public function test_second_superadmin_can_be_demoted_freely(): void
    {
        $this->user('admin@example.test', Role::Superadmin);
        $this->user('second@example.test', Role::Superadmin);

        $this->artisan('roles:assign', ['email' => 'second@example.test', 'role' => 'client'])
            ->assertSuccessful();
    }

    private function user(string $email, Role $role = Role::Client): User
    {
        $user = User::query()->create([
            'name' => 'Тест',
            'email' => $email,
            'password' => bcrypt('secret-password'),
        ]);

        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
