<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Капча в тестах не настроена — SmartCaptchaService работает fail-open.
        config(['services.smartcaptcha.secret' => null]);
    }

    public function test_visitor_can_register_as_client(): void
    {
        $this->postJson('/api/client/register', [
            'name' => 'Иван',
            'email' => 'ivan@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'phone' => '+7 999 123-45-67',
            'region' => 'Москва и МО',
        ])
            ->assertCreated()
            ->assertJsonPath('role', UserRole::CLIENT)
            ->assertJsonPath('user.email', 'ivan@example.test')
            ->assertJsonPath('user.region', 'Москва и МО')
            ->assertJsonPath('user.role', UserRole::CLIENT)
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('users', [
            'email' => 'ivan@example.test',
            'region' => 'Москва и МО',
        ]);
    }

    public function test_superadmin_email_cannot_register_as_client(): void
    {
        // Суперадмин должен существовать: роль теперь в БД, и «занятым» адрес
        // делает строка в users, а не список в окружении.
        $this->user('admin@example.test', Role::Superadmin);

        $this->postJson('/api/client/register', [
            'name' => 'Админ',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.test',
            'role' => UserRole::SUPERADMIN,
        ]);
    }

    public function test_client_can_login_and_read_own_profile(): void
    {
        $this->user('client@example.test');

        $login = $this->postJson('/api/client/login', [
            'email' => 'client@example.test',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonPath('role', UserRole::CLIENT);

        $token = $login->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/session/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'client@example.test')
            ->assertJsonPath('user.role', UserRole::CLIENT);
    }

    public function test_superadmin_cannot_login_as_client(): void
    {
        $this->user('admin@example.test', Role::Superadmin);

        $this->postJson('/api/client/login', [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    /**
     * Способность «кабинет клиента» принадлежит только роли «Клиент».
     *
     * Маршрута за ней сейчас нет: `/api/client/me` снят, а профиль отдаёт
     * `/api/session/me`, открытый любому вошедшему. Правило проверяется на
     * уровне карты прав, потому что именно оно закроет клиентские данные
     * (заказы, документы), когда они появятся, — и до тех пор не должно
     * молча разъехаться с ролями.
     */
    public function test_only_client_holds_the_cabinet_ability(): void
    {
        $this->assertTrue(Gate::forUser($this->user('client@example.test'))->allows('cabinet.view'));
        $this->assertFalse(Gate::forUser($this->user('admin@example.test', Role::Superadmin))->allows('cabinet.view'));
        $this->assertFalse(Gate::forUser($this->user('partner@example.test', Role::Partner))->allows('cabinet.view'));
    }

    public function test_region_is_optional(): void
    {
        $this->postJson('/api/client/register', [
            'name' => 'Без региона',
            'email' => 'noregion@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonPath('user.region', null);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->user('client@example.test');

        $this->postJson('/api/client/login', [
            'email' => 'client@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    private function user(string $email, Role $role = Role::Client): User
    {
        $user = User::query()->create([
            'name' => 'Тест',
            'email' => $email,
            'password' => bcrypt('secret-password'),
        ]);

        // Роль не fillable — назначаем явно, как это делает roles:sync-admins.
        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
