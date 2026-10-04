<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\RequestPasswordRecovery;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\PasswordRecovery;
use App\Services\PasswordRecoveryToken;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Tymon\JWTAuth\Facades\JWTAuth;

final class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private ChannelManager $notificationManager;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
        });
        $migration = require base_path('../leget-db/database/migrations/0001_01_01_000002_create_jobs_table.php');
        $migration->up();

        config([
            'services.smartcaptcha.secret' => null,
            'auth.password_reset.frontend_url' => 'https://leget.example.test',
            'auth.password_reset.mailer' => 'array',
        ]);
        $this->notificationManager = Notification::getFacadeRoot();
        Notification::fake();
    }

    public function test_link_is_mailed_only_to_registered_email_and_stored_hashed(): void
    {
        $user = $this->user('MixedCase@example.test');
        $original = $user->getRawOriginal('password');

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => 'MIXEDCASE@EXAMPLE.TEST',
            'redirect_url' => 'https://attacker.example.test',
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->workQueue();

        $notification = $this->notification($user);
        $record = DB::table('password_reset_tokens')->first();
        $this->assertSame($user->email, $record->email);
        $this->assertNotSame($notification->token, $record->token);
        $this->assertTrue(Hash::check($notification->token, $record->token));
        $this->assertSame($original, $user->fresh()->getRawOriginal('password'));
        $this->assertStringNotContainsString($notification->token, $response->getContent());
        $mail = $notification->toMail($user);
        $this->assertStringStartsWith('https://leget.example.test/reset-password?', $mail->viewData['resetUrl']);
        $this->assertStringNotContainsString('attacker', $mail->viewData['resetUrl']);
        parse_str(parse_url($mail->viewData['resetUrl'], PHP_URL_QUERY), $query);
        $this->assertSame($user->email, $query['email']);
        $this->assertSame(129, strlen($query['token']));
        $this->assertSame($notification->token, app(PasswordRecoveryToken::class)->verify($user->email, $query['token']));
        $this->assertSame(60, $mail->viewData['expiresIn']);
        $html = view($mail->view, $mail->viewData)->render();
        $this->assertStringContainsString('Ссылка действует 60 минут', $html);
    }

    public function test_unknown_email_and_broker_cooldown_have_the_same_response(): void
    {
        $user = $this->user();
        $known = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json();
        $repeat = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk()->json();

        $this->assertSame($known, $repeat);
        $this->assertSame($known, $unknown);
        $this->assertDatabaseCount('jobs', 3);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        foreach (DB::table('jobs')->get() as $row) {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $job = unserialize(Crypt::decrypt($payload['data']['command']));
            $this->assertInstanceOf(RequestPasswordRecovery::class, $job);
            $this->assertStringNotContainsString($job->email, $row->payload);
        }
        $this->workQueue();
        $this->workQueue();
        $this->workQueue();
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_reset_is_single_use_and_preserves_every_role_and_account_attribute(): void
    {
        foreach (Role::cases() as $role) {
            $user = $this->user($role->value.'@example.test', $role);
            $user->forceFill(['email_verified_at' => now(), 'university_enrolled_at' => now(), 'remember_token' => 'original'])->save();
            $attributes = $user->fresh()->getRawOriginal();
            $token = $this->requestToken($user);
            DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id]);

            $this->postJson('/api/auth/password/reset', $this->resetData($user, $token) + ['role' => 'superadmin', 'name' => 'Attacker'])
                ->assertOk()->assertJsonPath('success', true)->assertJsonMissingPath('token')->assertJsonMissingPath('user');

            $updated = $user->fresh();
            foreach (['name', 'email', 'role', 'phone', 'region', 'email_verified_at', 'university_enrolled_at', 'created_at'] as $field) {
                $this->assertSame($attributes[$field], $updated->getRawOriginal($field), $field);
            }
            $this->assertTrue(Hash::check('new-secret-password', $updated->password));
            $this->assertFalse(Hash::check('original-password', $updated->password));
            $this->assertSame(1, $updated->token_version);
            $this->assertNotSame('original', $updated->remember_token);
            $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
            $this->postJson('/api/auth/password/reset', $this->resetData($user, $token))
                ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token')->assertJsonValidationErrors('token');
            $this->assertSame(1, $user->fresh()->token_version);
        }
    }

    public function test_wrong_email_wrong_token_and_expired_link_do_not_change_password(): void
    {
        $user = $this->user();
        $token = $this->requestToken($user);
        $before = $user->password;

        $this->postJson('/api/auth/password/reset', [...$this->resetData($user, $token), 'email' => 'unknown@example.test'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->postJson('/api/auth/password/reset', $this->resetData($user, str_repeat('0', 64)))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/password/reset', $this->resetData($user, $token))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');

        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, $user->fresh()->token_version);
    }

    public function test_unsigned_forged_tampered_and_other_email_links_do_not_query_accounts_or_tokens(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $token = $this->requestToken($user);
        $forged = str_repeat('0', 64).'.'.str_repeat('1', 64);
        $changedRaw = ($token[0] === '0' ? '1' : '0').substr($token, 1);
        $changedSignature = substr($token, 0, -1).(substr($token, -1) === '0' ? '1' : '0');
        $responses = [];

        DB::enableQueryLog();
        foreach ([
            [$user->email, $forged],
            ['unknown@example.test', $forged],
            [$user->email, $changedRaw],
            [$user->email, $changedSignature],
            [$user->email, $this->notification($user)->token],
            [$other->email, $token],
            ['unknown@example.test', $token],
        ] as [$email, $invalidToken]) {
            DB::flushQueryLog();
            $responses[] = $this->postJson('/api/auth/password/reset', [...$this->resetData($user, $invalidToken), 'email' => $email])
                ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token')->json();
            // The response path cannot reveal account existence through SQL or bcrypt cost.
            $this->assertSame([], DB::getQueryLog());
        }
        DB::disableQueryLog();

        foreach ($responses as $response) {
            $this->assertSame($responses[0], $response);
        }
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
        $this->assertTrue(Hash::check('original-password', $other->fresh()->password));
        $this->assertTrue(Password::broker('users')->tokenExists($user, $this->notification($user)->token));
    }

    public function test_mailed_link_survives_key_rotation_only_with_previous_key_configured(): void
    {
        $user = $this->user('MixedCase@example.test');
        $token = $this->requestToken($user);
        $previousKey = Crypt::getKey();
        $rotated = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');
        Crypt::swap($rotated);

        DB::enableQueryLog();
        $this->postJson('/api/auth/password/reset', $this->resetData($user, $token))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $rotated->previousKeys([$previousKey]);

        $this->postJson('/api/auth/password/reset', [...$this->resetData($user, $token), 'email' => strtoupper($user->email)])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertTrue(Hash::check('new-secret-password', $user->fresh()->password));
    }

    public function test_account_resolution_cannot_establish_a_snapshot_before_the_transaction_lock(): void
    {
        $user = $this->user();
        $recovery = app(PasswordRecovery::class);
        $outsideLevel = DB::transactionLevel();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'level' => $query->connection->transactionLevel()];
        });

        $recovery->issueLink($user->email);
        $issueQueries = $queries;
        $token = $this->mailedToken($user, $this->notification($user));
        $queries = [];
        $this->assertTrue($recovery->reset($this->resetData($user, $token)));

        // SQLite cannot exercise MySQL row-lock waits. Guard the query boundary
        // that keeps its repeatable-read snapshot fresh after a concurrent reset.
        foreach ([$issueQueries, $queries] as $operationQueries) {
            $this->assertStringContainsString('LOWER(email)', $operationQueries[0]['sql']);
            $this->assertSame($outsideLevel, $operationQueries[0]['level']);
            $this->assertStringContainsString('"users"."id"', $operationQueries[1]['sql']);
            $this->assertSame([$user->getKey()], $operationQueries[1]['bindings']);
            foreach (array_slice($operationQueries, 1) as $query) {
                $this->assertSame($outsideLevel + 1, $query['level']);
            }
        }
    }

    public function test_new_link_invalidates_previous_link(): void
    {
        $user = $this->user();
        $first = $this->requestToken($user);
        $this->travel(61)->seconds();
        $second = $this->requestToken($user);

        $this->assertNotSame($first, $second);
        $this->postJson('/api/auth/password/reset', $this->resetData($user, $first))
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->postJson('/api/auth/password/reset', $this->resetData($user, $second))->assertOk();
    }

    public function test_enqueue_failure_restores_previous_token_and_does_not_expose_account_or_secret(): void
    {
        $user = $this->user();
        $first = $this->requestToken($user);
        $this->travel(61)->seconds();
        $before = DB::table('password_reset_tokens')->first();
        Notification::swap(\Mockery::mock(ChannelManager::class));
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP secret: '.$first));
        Log::spy();

        $known = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json();
        $this->workQueue();
        $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk()->json();
        $this->workQueue();

        $this->assertSame($known, $unknown);
        $this->assertEquals($before, DB::table('password_reset_tokens')->first());
        Log::shouldHaveReceived('error')->with('Password recovery request processing failed.', ['exception' => RuntimeException::class])->once();
        Notification::fake();
        $this->travel(61)->seconds();
        $this->workQueue();
        $second = $this->mailedToken($user, $this->notification($user));
        $this->assertNotSame($first, $second);
    }

    public function test_log_mail_transport_is_rejected_without_rendering_a_bearer_link(): void
    {
        $user = $this->user();
        config(['auth.password_reset.mailer' => 'failover']);
        $notification = new ResetPasswordNotification('secret-token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Password reset mail must not be logged.');
        $notification->toMail($user);
    }

    #[DataProvider('mailersWithLogDsn')]
    public function test_log_dsn_cannot_bypass_private_mail_transport_checks(string $mailer, string $dsn): void
    {
        $user = $this->user();
        config([
            'auth.password_reset.mailer' => $mailer,
            'mail.mailers.smtp.url' => $dsn,
            'mail.mailers.private-failover' => ['transport' => 'failover', 'mailers' => ['array', 'smtp']],
        ]);
        $this->mock(MailChannel::class)->shouldNotReceive('send');
        $notification = new ResetPasswordNotification(str_repeat('a', 64));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Password reset mail must not be logged.');
        $notification->toMail($user);
    }

    public static function mailersWithLogDsn(): array
    {
        return [
            'direct SMTP' => ['smtp', 'log://localhost'],
            'failover child' => ['private-failover', 'log://localhost'],
            'uppercase SMTP' => ['smtp', 'LOG://localhost'],
            'uppercase failover child' => ['private-failover', 'LOG://localhost'],
            'camelized SMTP' => ['smtp', 'l-o-g://localhost'],
            'camelized failover child' => ['private-failover', 'l-o-g://localhost'],
        ];
    }

    public function test_password_validation_does_not_consume_link(): void
    {
        $user = $this->user();
        $token = $this->requestToken($user);

        foreach ([['short', 'short'], ['new-password', 'different'], [str_repeat('я', 40), str_repeat('я', 40)], ["password\0value", "password\0value"]] as [$password, $confirmation]) {
            $this->postJson('/api/auth/password/reset', [...$this->resetData($user, $token), 'password' => $password, 'password_confirmation' => $confirmation])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Password::broker('users')->tokenExists($user, $this->rawToken($token)));
        $this->assertSame(0, $user->fresh()->token_version);
    }

    public function test_captcha_is_checked_before_sending_mail(): void
    {
        $user = $this->user();
        config(['services.smartcaptcha.secret' => 'test-secret']);
        Http::fake(['*' => Http::response(['status' => 'failed'])]);

        $this->postJson('/api/auth/password/forgot', ['email' => $user->email, 'captcha_token' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('captcha_token');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_request_limit_is_shared_by_email_case_and_same_for_missing_accounts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $email = $i % 2 === 0 ? 'UNKNOWN@example.test' : 'unknown@example.test';
            $this->postJson('/api/auth/password/forgot', ['email' => $email])->assertOk();
        }
        $this->postJson('/api/auth/password/forgot', ['email' => 'Unknown@example.test'])->assertTooManyRequests();
    }

    public function test_recovery_responses_are_private_including_validation_and_rate_limit(): void
    {
        $this->postJson('/api/auth/password/forgot', [])->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/auth/password/reset', [])->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_password_reset_revokes_old_and_legacy_jwts_and_new_login_works(): void
    {
        $user = $this->user();
        $old = JWTAuth::fromUser($user);
        $legacy = $this->legacyToken($user);
        $this->bearer($legacy)->getJson('/api/session/me')->assertOk();
        $token = $this->requestToken($user);
        $this->postJson('/api/auth/password/reset', $this->resetData($user, $token))->assertOk();

        $this->bearer($old)->getJson('/api/session/me')->assertUnauthorized();
        $this->bearer($legacy)->getJson('/api/session/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/client/login', ['email' => $user->email, 'password' => 'original-password'])->assertUnprocessable();
        Auth::forgetGuards();
        $login = $this->postJson('/api/client/login', ['email' => $user->email, 'password' => 'new-secret-password'])->assertOk();
        $this->assertSame(1, JWTAuth::setToken($login->json('token'))->getPayload()->get('token_version'));
        $this->assertSame(sha1(User::class), JWTAuth::getPayload()->get('prv'));
        $this->bearer($login->json('token'))->getJson('/api/session/me')->assertOk()->assertJsonPath('user.role', 'client')->assertJsonMissingPath('user.token_version');
    }

    public function test_partial_database_failure_rolls_back_password_version_and_token_consumption(): void
    {
        $user = $this->user();
        $token = $this->requestToken($user);
        $before = $user->password;
        Schema::drop('sessions');

        $this->postJson('/api/auth/password/reset', $this->resetData($user, $token))->assertStatus(503);

        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, $user->fresh()->token_version);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $this->rawToken($token)));
    }

    public function test_database_queue_payload_is_encrypted_and_request_does_not_deliver_mail(): void
    {
        $this->realQueue();
        $user = $this->user();
        $this->mock(MailChannel::class)->shouldNotReceive('send');

        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();

        $row = DB::table('jobs')->sole();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        $this->assertInstanceOf(RequestPasswordRecovery::class, $job);
        $this->assertSame($user->email, $job->email);
        $this->assertStringNotContainsString($user->email, $row->payload);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->workQueue();
        $row = DB::table('jobs')->sole();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize(Crypt::decrypt($payload['data']['command']));
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $this->assertInstanceOf(ResetPasswordNotification::class, $job->notification);
        $this->assertSame('password-recovery', $row->queue);
        $this->assertSame('password-recovery', $job->connection);
        $this->assertTrue($job->shouldBeEncrypted);
        $this->assertStringNotContainsString($job->notification->token, $row->payload);
        $this->assertStringNotContainsString($user->email, $row->payload);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $job->notification->token));
    }

    public function test_smtp_failure_is_sanitized_then_worker_retries_same_link_successfully(): void
    {
        $this->realQueue();
        $user = $this->user();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->workQueue();
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $token = unserialize(Crypt::decrypt($payload['data']['command']))->notification->token;
        $mail = $this->mock(MailChannel::class);
        $mail->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP secret '.$token));
        $mail->shouldReceive('send')->once()->andReturn(null);
        Log::spy();

        $this->workQueue();

        $row = DB::table('jobs')->sole();
        $this->assertSame(1, $row->attempts);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
        $this->assertDatabaseCount('failed_jobs', 0);
        Log::shouldHaveReceived('error')->with('Password recovery mail delivery failed.', ['exception' => RuntimeException::class])->once();
        $this->assertStringNotContainsString($token, Artisan::output());
        $this->travel(61)->seconds();

        $this->workQueue();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
    }

    public function test_exhausted_smtp_failure_stores_only_encrypted_job_and_sanitized_exception(): void
    {
        $this->realQueue();
        $user = $this->user();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->workQueue();
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $token = unserialize(Crypt::decrypt($payload['data']['command']))->notification->token;
        DB::table('jobs')->update(['attempts' => 4]);
        $this->mock(MailChannel::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP_PRIVATE_SECRET '.$token));

        $this->workQueue();

        $this->assertDatabaseCount('jobs', 0);
        $failed = DB::table('failed_jobs')->sole();
        $this->assertStringNotContainsString($token, $failed->payload);
        $this->assertStringNotContainsString($token, $failed->exception);
        $this->assertStringNotContainsString('SMTP_PRIVATE_SECRET', $failed->exception);
        $this->assertStringContainsString('Password recovery email delivery failed.', $failed->exception);
        $this->assertTrue(Password::broker('users')->tokenExists($user, $token));
    }

    public function test_failed_database_enqueue_rolls_back_token_and_allows_immediate_retry(): void
    {
        $user = $this->user();
        $first = $this->requestToken($user);
        $this->travel(61)->seconds();
        $before = DB::table('password_reset_tokens')->sole();
        $this->realQueue();
        Schema::drop('jobs');

        $known = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
        $this->assertEquals($before, DB::table('password_reset_tokens')->sole());
        $this->assertTrue(Password::broker('users')->tokenExists($user, $this->rawToken($first)));
        Schema::drop('failed_jobs');
        Schema::drop('job_batches');
        $migration = require base_path('../leget-db/database/migrations/0001_01_01_000002_create_jobs_table.php');
        $migration->up();

        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->assertDatabaseCount('jobs', 1);
        $this->workQueue();
        $this->assertFalse(Password::broker('users')->tokenExists($user, $this->rawToken($first)));
    }

    public function test_worker_skips_superseded_consumed_and_expired_links(): void
    {
        $this->realQueue();
        $mail = $this->mock(MailChannel::class);
        $mail->shouldNotReceive('send');
        $user = $this->user();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->workQueue();
        $this->travel(61)->seconds();
        $this->app->make(PasswordRecovery::class)->issueLink($user->email);
        // The first queued notification has been replaced, so the worker drops it.
        $this->workQueue();
        $this->assertDatabaseCount('jobs', 1);
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $notification = unserialize(Crypt::decrypt($payload['data']['command']))->notification;
        $token = $this->mailedToken($user, $notification);
        $this->postJson('/api/auth/password/reset', $this->resetData($user, $token))->assertOk();
        $this->workQueue();
        $this->assertDatabaseCount('jobs', 0);

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->workQueue();
        $this->travel(61)->minutes();
        $this->workQueue();
        $this->assertDatabaseCount('jobs', 0);
        // Expired job may enter failed_jobs without a raw token in its encrypted payload.
        foreach (DB::table('failed_jobs')->get() as $failure) {
            $this->assertStringNotContainsString($token, $failure->payload);
        }
    }

    private function realQueue(): void
    {
        Notification::swap($this->notificationManager);
    }

    private function workQueue(): void
    {
        Artisan::call('queue:work', ['connection' => 'password-recovery', '--queue' => 'password-recovery', '--once' => true, '--sleep' => 0, '--tries' => 5]);
    }

    private function user(string $email = 'client@example.test', Role $role = Role::Client): User
    {
        $user = User::query()->create(['name' => 'Тест', 'email' => $email, 'password' => 'original-password', 'phone' => '+79991234567', 'region' => 'Москва']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function requestToken(User $user): string
    {
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->workQueue();

        return $this->mailedToken($user, $this->notification($user));
    }

    private function mailedToken(User $user, ResetPasswordNotification $notification): string
    {
        $mail = $notification->toMail($user);
        parse_str(parse_url($mail->viewData['resetUrl'], PHP_URL_QUERY), $query);

        return $query['token'];
    }

    private function rawToken(string $token): string
    {
        return explode('.', $token, 2)[0];
    }

    private function notification(User $user): ResetPasswordNotification
    {
        return Notification::sent($user, ResetPasswordNotification::class)->last();
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'new-secret-password', 'password_confirmation' => 'new-secret-password'];
    }

    private function bearer(string $token): self
    {
        Auth::forgetGuards();
        $this->app['tymon.jwt']->unsetToken();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function legacyToken(User $user): string
    {
        $claims = $user->getJWTCustomClaims();
        unset($claims['token_version']);
        $subject = new class($user, $claims) implements JWTSubject
        {
            public function __construct(private User $user, private array $claims) {}

            public function getJWTIdentifier(): mixed
            {
                return $this->user->getKey();
            }

            public function getJWTCustomClaims(): array
            {
                return $this->claims;
            }
        };

        // Keep the same locked subject model as real legacy User tokens.
        return JWTAuth::claims(['prv' => sha1(User::class)])->fromUser($subject);
    }
}
