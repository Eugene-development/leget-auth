<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * «Кто я» отвечает любой роли. Именно по этому ответу leget-main решает,
     * чей кабинет показывать: снятый `/api/client/me` был закрыт
     * `can:cabinet.view` и партнёру возвращал 403.
     *
     * Три отдельных теста, а не цикл: и guard, и singleton JWTAuth кэшируют
     * разобранный токен на весь тест, поэтому второй запрос внутри одного
     * теста отвечал бы про пользователя из первого.
     */
    public function test_session_me_answers_client(): void
    {
        $this->assertSessionRole(Role::Client);
    }

    public function test_session_me_answers_superadmin(): void
    {
        $this->assertSessionRole(Role::Superadmin);
    }

    public function test_session_me_answers_partner(): void
    {
        $this->assertSessionRole(Role::Partner);
    }

    public function test_guest_has_no_session(): void
    {
        $this->getJson('/api/session/me')->assertUnauthorized();
    }

    /**
     * Роль в claim'ах нужна leget-main, чтобы отрисовать шапку в SSR без
     * запроса к auth-сервису. Границей доступа claim не является — он снимок
     * на момент выдачи и после смены роли врёт до истечения TTL.
     */
    public function test_token_carries_role_claim(): void
    {
        $partner = $this->user('partner@example.test', Role::Partner);

        $payload = JWTAuth::setToken(JWTAuth::fromUser($partner))->getPayload();

        $this->assertSame(Role::Partner->value, $payload->get('role'));
    }

    public function test_claim_goes_stale_but_access_follows_the_database(): void
    {
        $user = $this->user('demoted@example.test', Role::Partner);
        $token = JWTAuth::fromUser($user);

        // Роль сняли уже после выдачи токена.
        $user->forceFill(['role' => Role::Client])->save();

        $this->assertSame(Role::Partner->value, JWTAuth::setToken($token)->getPayload()->get('role'));

        // Ответ сервера всё равно про сегодняшнюю роль, а не про ту, что в токене.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/session/me')
            ->assertOk()
            ->assertJsonPath('user.role', Role::Client->value);
    }

    private function assertSessionRole(Role $role): void
    {
        $email = $role->value.'@example.test';
        $user = $this->user($email, $role);

        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
            ->getJson('/api/session/me')
            ->assertOk()
            ->assertJsonPath('user.email', $email)
            ->assertJsonPath('user.role', $role->value);
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
