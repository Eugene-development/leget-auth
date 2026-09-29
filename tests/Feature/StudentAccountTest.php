<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentAccountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.smartcaptcha.secret' => null]);
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->decimal('balance', 15, 2)->default(0);
            $t->timestamps();
        });
    }

    private function account(Role $role, string $email = ''): User
    {
        $user = User::create(['name' => 'Существующее имя', 'email' => $email ?: $role->value.'@test.local', 'password' => 'Original-Password-123']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_every_role_can_enroll_without_replacing_role_identity_or_password(): void
    {
        foreach (Role::cases() as $role) {
            $user = $this->account($role);
            $password = $user->password;
            $this->actingAs($user, 'api')->postJson('/api/university/enroll', ['role' => 'superadmin'])->assertOk();
            $enrolledAt = $user->fresh()->university_enrolled_at;
            $this->travel(1)->hours();
            $this->actingAs($user->fresh(), 'api')->postJson('/api/university/enroll')->assertOk();
            $fresh = $user->fresh();
            $this->assertSame($role, $fresh->role);
            $this->assertSame($password, $fresh->password);
            $this->assertSame('Существующее имя', $fresh->name);
            $this->assertEquals($enrolledAt, $fresh->university_enrolled_at);
            $this->assertTrue($fresh->hasAbility('university.study'));
            $this->assertSame($role === Role::Student ? ['student'] : [$role->value, 'student'], $fresh->roleNames());
            $this->travelBack();
        }
        $this->assertDatabaseCount('users', 7);
    }

    public function test_university_login_accepts_all_roles_and_does_not_enroll_implicitly(): void
    {
        foreach (Role::cases() as $role) {
            $user = $this->account($role);
            $this->postJson('/api/university/login', ['email' => strtoupper($user->email), 'password' => 'Original-Password-123'])
                ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', $role->value);
            $this->assertNull($user->fresh()->university_enrolled_at);
        }
        $this->postJson('/api/client/login', ['email' => 'superadmin@test.local', 'password' => 'Original-Password-123'])->assertForbidden();
        $this->postJson('/api/university/login', ['email' => 'superadmin@test.local', 'password' => 'Wrong'])->assertUnprocessable()->assertJsonMissingPath('user')->assertJsonMissingPath('role');
    }

    public function test_occupied_email_never_registers_or_reveals_the_existing_role(): void
    {
        $user = $this->account(Role::Superadmin, 'MixedCase@Test.Local');
        $password = $user->password;
        foreach (['/api/university/register', '/api/client/register', '/api/auth/register'] as $endpoint) {
            $this->postJson($endpoint, ['email' => '  mixedcase@test.local ', 'name' => 'Не перезаписывать', 'password' => 'Different-Password-456', 'password_confirmation' => 'Different-Password-456'])
                ->assertConflict()->assertJsonPath('code', 'account_exists')->assertJsonMissingPath('user')->assertJsonMissingPath('role')->assertJsonMissingPath('token');
        }
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('Существующее имя', $user->fresh()->name);
        $this->assertNull($user->fresh()->university_enrolled_at);
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/university/login', ['email' => 'mixedcase@test.local', 'password' => 'Original-Password-123'])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_student_only_has_no_client_or_staff_permissions_and_can_add_client(): void
    {
        $user = $this->account(Role::Student);
        $this->assertSame(['student'], $user->roleNames());
        foreach (['cabinet.view', 'promo.client', 'users.curate', 'crm.work', 'crm.admin'] as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability));
        }
        $this->actingAs($user, 'api')->postJson('/api/client/activate', ['role' => 'superadmin', 'user_id' => 900])->assertOk()->assertJsonPath('user.roles', ['client', 'student']);
        $this->actingAs($user->fresh(), 'api')->postJson('/api/client/activate')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_owner_activation_is_atomic_repeatable_and_does_not_reset_wallet(): void
    {
        $user = $this->account(Role::Student);
        $password = $user->password;
        $this->actingAs($user, 'api')->postJson('/api/auth/activate-owner')->assertOk()->assertJsonPath('user.roles', ['admin', 'student']);
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => 321]);
        $this->actingAs($user->fresh(), 'api')->postJson('/api/auth/activate-owner')->assertOk();
        $this->assertDatabaseCount('wallets', 1);
        $this->assertEquals(321, DB::table('wallets')->where('user_id', $user->id)->value('balance'));
        $this->assertSame($password, $user->fresh()->password);
        $this->actingAs($user->fresh(), 'api')->postJson('/api/client/activate')->assertConflict();
    }

    public function test_second_role_cannot_replace_other_roles_or_be_added_by_a_guest(): void
    {
        $this->postJson('/api/client/activate')->assertUnauthorized();
        $this->postJson('/api/auth/activate-owner')->assertUnauthorized();
        $this->postJson('/api/university/enroll')->assertUnauthorized();
        foreach ([Role::Manager, Role::Partner, Role::Curator, Role::Superadmin] as $role) {
            $user = $this->account($role);
            $this->actingAs($user, 'api')->postJson('/api/client/activate')->assertConflict();
            $this->actingAs($user, 'api')->postJson('/api/auth/activate-owner')->assertConflict();
            $this->assertSame($role, $user->fresh()->role);
        }
    }

    public function test_cli_adds_education_to_staff_and_preserves_student_on_role_assignment(): void
    {
        $user = $this->account(Role::Superadmin);
        $this->artisan('roles:assign', ['email' => $user->email, 'role' => 'student'])->assertSuccessful();
        $this->assertSame(Role::Superadmin, $user->fresh()->role);
        $this->assertTrue($user->fresh()->hasAbility('university.study'));
        $student = $this->account(Role::Student);
        $this->artisan('roles:assign', ['email' => $student->email, 'role' => 'curator'])->assertSuccessful();
        $this->assertSame(['curator', 'student'], $student->fresh()->roleNames());
    }
}
