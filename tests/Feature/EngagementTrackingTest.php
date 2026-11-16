<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EngagementTrackingTest extends TestCase
{
    private string $secret = 'test-only-engagement-secret-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['forms.context_secret' => $this->secret]);
        Cache::flush();
    }

    private function context(array $overrides = []): string
    {
        $data = array_replace([
            'domain' => 'one.test', 'session' => '00000000-0000-4000-8000-000000000001',
            'ip' => '192.0.2.1', 'goal' => 'promo_1_home_consultation_open', 'timestamp' => time(),
        ], $overrides);
        $payload = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', 'leget-engagement:v1:'.$payload, $this->secret);
    }

    private function send(array $overrides = [])
    {
        return $this->postJson('/api/track/engagement', [], ['X-Leget-Engagement-Context' => $this->context($overrides)]);
    }

    public function test_only_fresh_purpose_signed_valid_context_is_accepted(): void
    {
        $this->postJson('/api/track/engagement')->assertForbidden();
        $this->postJson('/api/track/engagement', [], ['X-Leget-Engagement-Context' => $this->context().'0'])->assertForbidden();
        foreach ([['timestamp' => time() - 61], ['timestamp' => time() + 61], ['ip' => 'bad'],
            ['domain' => 'evil/path'], ['session' => 'fake'], ['goal' => 'arbitrary']] as $bad) {
            $this->send($bad)->assertForbidden();
        }
        $this->send()->assertOk()->assertExactJson(['allowed' => true])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_session_limit_is_shared_across_goals_and_ips_and_recovers(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->send(['goal' => 'promo_1_goal_'.$i, 'ip' => '192.0.2.'.($i + 1)])->assertOk();
        }
        $this->send()->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travel(61)->seconds();
        $this->send()->assertOk();
    }

    public function test_ip_limit_cannot_be_bypassed_by_new_sessions_and_is_site_scoped(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->send(['session' => (string) Str::uuid()])->assertOk();
        }
        $this->send(['session' => (string) Str::uuid()])->assertStatus(429);
        $this->send(['domain' => 'two.test'])->assertOk();
        $this->send(['ip' => '2001:db8::1', 'session' => (string) Str::uuid()])->assertOk();
    }

    public function test_unsigned_forwarded_ip_and_client_fields_cannot_change_quota(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->send()->assertOk();
        }
        $this->postJson('/api/track/engagement', ['ip' => '203.0.113.1', 'session' => (string) Str::uuid()], [
            'X-Leget-Engagement-Context' => $this->context(), 'X-Forwarded-For' => '203.0.113.1',
        ])->assertStatus(429);
    }

    public function test_lock_contention_refuses_without_consuming_a_session_budget(): void
    {
        $scope = hash_hmac('sha256', 'one.test', $this->secret);
        $key = 'engagement:ip:'.$scope.':'.hash_hmac('sha256', '192.0.2.1', $this->secret);
        $lock = Cache::lock('engagement:lock:'.$key, 5);
        $this->assertTrue($lock->get());
        $this->send()->assertStatus(429);
        $lock->release();
        for ($i = 0; $i < 30; $i++) {
            $this->send()->assertOk();
        }
    }

    public function test_parallel_php_workers_share_database_limits_atomically(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        $path = sys_get_temp_dir().'/leget-engagement-'.Str::uuid().'.sqlite';
        DB::statement('VACUUM INTO ?', [$path]);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
    'cache.default' => 'database', 'cache.prefix' => 'engagement-process-test',
    'forms.context_secret' => 'test-only-engagement-secret-32-characters']);
Illuminate\Support\Facades\DB::purge();
$statuses = [];
for ($i = 0; $i < 12; $i++) {
    $request = Illuminate\Http\Request::create('/api/track/engagement', 'POST', [], [], [],
        ['HTTP_X_LEGET_ENGAGEMENT_CONTEXT' => $argv[2]]);
    $statuses[] = app(App\Http\Controllers\EngagementTrackingController::class)->track($request)->getStatusCode();
}
echo json_encode($statuses);
PHP;
        $workers = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $worker = new Process([PHP_BINARY, '-r', $script, $path, $this->context()], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'DB_URL' => '',
                    'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
                ]);
                $worker->setTimeout(15);
                $worker->start();
                $workers[] = $worker;
            }
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                array_push($statuses, ...json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            }
            $this->assertCount(30, array_filter($statuses, fn ($status) => $status === 200));
            $this->assertCount(18, array_filter($statuses, fn ($status) => $status === 429));
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(1);
            }
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
