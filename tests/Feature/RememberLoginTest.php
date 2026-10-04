<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\LoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RememberLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.smartcaptcha.secret' => null,
            'sms.driver' => 'test',
            'jwt.ttl' => 60,
            'jwt.remember_ttl' => 43200,
        ]);
        $this->freezeTime();
    }

    public static function passwordLogins(): iterable
    {
        foreach (Role::cases() as $role) {
            $paths = ['/api/auth/login', '/api/university/login'];
            $paths[] = $role === Role::Superadmin ? '/api/admin/login' : '/api/client/login';
            foreach ($paths as $path) {
                foreach ([false, true] as $remember) {
                    foreach ([false, true] as $enrolled) {
                        yield "$path/$role->value/remember=$remember/enrolled=$enrolled" => [$path, $role, $remember, $enrolled];
                    }
                }
            }
        }
    }

    #[DataProvider('passwordLogins')]
    public function test_password_login_lifetime_for_every_role_and_education_combination(string $path, Role $role, bool $remember, bool $enrolled): void
    {
        $user = $this->user($role, $enrolled);
        $before = $user->fresh()->getAttributes();
        $response = $this->postJson($path, $this->credentials($user) + ['remember' => $remember])->assertOk();

        $this->assertSession($response->json(), $user, $remember);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function smsLogins(): iterable
    {
        foreach (Role::cases() as $role) {
            foreach ([false, true] as $remember) {
                foreach ([false, true] as $enrolled) {
                    yield "$role->value/remember=$remember/enrolled=$enrolled" => [$role, $remember, $enrolled];
                }
            }
        }
    }

    #[DataProvider('smsLogins')]
    public function test_sms_login_uses_same_lifetime_for_every_role(Role $role, bool $remember, bool $enrolled): void
    {
        $user = $this->user($role, $enrolled);
        $before = $user->fresh()->getAttributes();
        $data = $this->smsChallenge($role === Role::Superadmin ? 'admin' : 'client');
        $response = $this->postJson('/api/sms/verify', $data + ['remember' => $remember])->assertOk();

        $this->assertSession($response->json(), $user, $remember);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public static function loginPaths(): iterable
    {
        yield 'owner' => ['/api/auth/login', Role::Admin];
        yield 'client' => ['/api/client/login', Role::Client];
        yield 'university' => ['/api/university/login', Role::Student];
        yield 'admin' => ['/api/admin/login', Role::Superadmin];
    }

    #[DataProvider('loginPaths')]
    public function test_omitted_remember_keeps_configured_standard_lifetime(string $path, Role $role): void
    {
        config(['jwt.ttl' => 1440]);
        $user = $this->user($role);
        $data = $this->postJson($path, $this->credentials($user))->assertOk()->json();
        $this->assertSession($data, $user, false);
    }

    #[DataProvider('loginPaths')]
    public function test_invalid_remember_values_are_rejected_without_a_token(string $path, Role $role): void
    {
        $user = $this->user($role);
        foreach (['false', 'true', null, ['remember' => true], 2] as $invalid) {
            $this->postJson($path, $this->credentials($user) + ['remember' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('remember')->assertJsonMissingPath('token');
        }
    }

    public function test_sms_rejects_invalid_remember_before_consuming_the_code_and_defaults_to_false(): void
    {
        $user = $this->user(Role::Client);
        $data = $this->smsChallenge();
        $this->postJson('/api/sms/verify', $data + ['remember' => 'false'])
            ->assertUnprocessable()->assertJsonValidationErrors('remember');
        $response = $this->postJson('/api/sms/verify', $data)->assertOk();
        $this->assertSession($response->json(), $user, false);
    }

    public function test_remembered_token_remains_valid_after_standard_ttl_and_expires_after_thirty_days(): void
    {
        $user = $this->user(Role::Client);
        $credentials = $this->credentials($user);
        $short = $this->postJson('/api/client/login', $credentials + ['remember' => false])->assertOk()->json('token');
        $long = $this->postJson('/api/client/login', $credentials + ['remember' => true])->assertOk()->json('token');

        $this->travel(61)->minutes();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->withToken($short)->getJson('/api/session/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->withToken($long)->getJson('/api/session/me')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->travel(30)->days();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->withToken($long)->getJson('/api/session/me')->assertUnauthorized();
    }

    public function test_remembered_token_is_revoked_after_password_version_changes(): void
    {
        $user = $this->user(Role::Client);
        $token = $this->postJson('/api/client/login', $this->credentials($user) + ['remember' => true])->assertOk()->json('token');
        $user->increment('token_version');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/session/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_remembered_token(): void
    {
        $user = $this->user(Role::Client);
        $token = $this->postJson('/api/client/login', $this->credentials($user) + ['remember' => true])->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->withToken($token)->getJson('/api/session/me')->assertUnauthorized();
    }

    public function test_lifetime_does_not_leak_into_the_next_login_or_registration_token(): void
    {
        $user = $this->user(Role::Client);
        $factory = JWTAuth::factory();
        $previousTtl = $factory->getTTL();
        $sessions = app(LoginSession::class);
        $sessions->forUser($user, true);
        $this->assertSame($previousTtl, $factory->getTTL());
        $this->assertSame([], JWTAuth::getCustomClaims());
        $this->assertFalse($sessions->attempt(['email' => $user->email, 'password' => 'wrong'], true));
        $this->assertSame($previousTtl, $factory->getTTL());
        $short = $sessions->forUser($user);
        $payload = JWTAuth::setToken(JWTAuth::fromUser($user))->getPayload();
        $this->assertSame($previousTtl * 60, $payload->get('exp') - $payload->get('iat'));
        $this->assertNull($payload->get('remember'));
        $this->assertSession($short, $user, false);
    }

    public static function accountUpdates(): iterable
    {
        foreach (['/api/university/enroll', '/api/client/activate', '/api/auth/activate-owner'] as $path) {
            foreach ([false, true] as $remember) {
                yield "$path/remember=$remember" => [$path, $remember];
            }
        }
    }

    #[DataProvider('accountUpdates')]
    public function test_account_updates_preserve_persistence_and_original_expiry(string $path, bool $remember): void
    {
        if ($path === '/api/auth/activate-owner') {
            (require __DIR__.'/../../../leget-db/database/migrations/2026_04_17_000001_create_wallets_table.php')->up();
        }
        $user = $this->user(Role::Student);
        $login = $this->postJson('/api/university/login', $this->credentials($user) + ['remember' => $remember])->assertOk()->json();
        $original = JWTAuth::setToken($login['token'])->getPayload();
        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $updated = $this->withToken($login['token'])->postJson($path, ['remember' => ! $remember])
            ->assertOk()->assertJsonPath('remember', $remember)->json();
        $payload = JWTAuth::setToken($updated['token'])->getPayload();

        $this->assertSame($original->get('exp'), $payload->get('exp'));
        $this->assertSame($login['expires_in'] - 120, $updated['expires_in']);
        $this->assertSame($remember, $payload->get('remember'));
        $this->assertSame($user->fresh()->roleNames(), $payload->get('roles'));
        $this->assertSame(3, $payload->get('token_version'));
    }

    private function assertSession(array $data, User $user, bool $remember): void
    {
        $seconds = (int) config($remember ? 'jwt.remember_ttl' : 'jwt.ttl') * 60;
        $this->assertSame($seconds, $data['expires_in']);
        $this->assertSame($remember, $data['remember']);
        $payload = JWTAuth::setToken($data['token'])->getPayload();
        $this->assertSame($seconds, $payload->get('exp') - $payload->get('iat'));
        $this->assertSame($remember, $payload->get('remember'));
        $this->assertSame((string) $user->id, (string) $payload->get('sub'));
        $this->assertSame($user->role->value, $payload->get('role'));
        $this->assertSame($user->roleNames(), $payload->get('roles'));
        $this->assertSame(3, $payload->get('token_version'));
    }

    private function user(Role $role, bool $enrolled = false): User
    {
        $user = User::create([
            'name' => 'Remember test', 'email' => $role->value.'@example.test',
            'password' => 'secret-password', 'phone' => '+79991234567',
        ]);
        $user->forceFill(['role' => $role, 'university_enrolled_at' => $enrolled ? now() : null, 'token_version' => 3])->save();

        return $user;
    }

    private function credentials(User $user): array
    {
        return ['email' => $user->email, 'password' => 'secret-password'];
    }

    private function smsChallenge(string $context = 'client'): array
    {
        $data = ['phone' => '+79991234567', 'context' => $context];
        $challenge = $this->postJson('/api/sms/request', $data)->assertOk()->json();

        return $data + ['challenge_id' => $challenge['challenge_id'], 'code' => $challenge['test_code']];
    }
}
