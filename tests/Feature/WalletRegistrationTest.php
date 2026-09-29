<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SmartCaptchaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class WalletRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_atomically_creates_zero_balance_wallet(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->decimal('balance', 15, 2)->default(0);
            $t->timestamps();
        });
        Notification::fake();
        $captcha = \Mockery::mock(SmartCaptchaService::class);
        $captcha->shouldReceive('verify')->andReturn(true);
        $captcha->shouldReceive('clientIp')->andReturn('127.0.0.1');
        $this->app->instance(SmartCaptchaService::class, $captcha);
        JWTAuth::shouldReceive('fromUser')->once()->andReturn('audit-token');
        JWTAuth::shouldReceive('factory->getTTL')->once()->andReturn(60);
        $response = $this->postJson('/api/auth/register', ['name' => 'Audit', 'email' => 'audit@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123']);
        $response->assertCreated()->assertJsonPath('success', true)->assertJsonPath('user.role', 'admin');
        $this->assertSame(1, User::count());
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertEquals(0, DB::table('wallets')->value('balance'));
        $this->assertSame(User::first()->id, DB::table('wallets')->value('user_id'));
    }
}
