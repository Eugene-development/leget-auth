<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\FormTestCase;

/** Separate CLI runtimes sharing a synthetic database, never real SMTP. */
class FormMailProcessTest extends FormTestCase
{
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    private function fixture(): string
    {
        $this->postJson('/api/notify/service-request', ['service_type' => 'consultation', 'name' => 'Process fixture', 'phone' => '123', 'submission_key' => (string) Str::uuid()])->assertStatus(202);
        $path = sys_get_temp_dir().'/leget-mail-process-'.Str::uuid().'.sqlite';
        $this->paths[] = $path;
        DB::statement('VACUUM INTO ?', [$path]);

        return $path;
    }

    private function worker(string $path, bool $watch = false): Process
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000, 'mail.default' => 'array', 'forms.recipient' => 'synthetic@example.com']);
Illuminate\Support\Facades\DB::purge();
Illuminate\Support\Facades\Mail::fake();
if ($argv[2] === 'watch') {
    Illuminate\Support\Facades\DB::listen(function () {
        static $ready = false;
        if (! $ready) { fwrite(STDOUT, "READY\n"); fflush(STDOUT); $ready = true; }
    });
}
exit(Illuminate\Support\Facades\Artisan::call('forms:work-mail', $argv[2] === 'watch' ? ['--sleep' => 1] : ['--once' => true]));
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $path, $watch ? 'watch' : 'once'], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(15);
        $process->start();

        return $process;
    }

    public function test_two_cli_workers_do_not_send_the_same_delivery_twice(): void
    {
        $path = $this->fixture();
        $first = $this->worker($path);
        $second = $this->worker($path);
        try {
            $this->assertSame(0, $first->wait(), $first->getErrorOutput().$first->getOutput());
            $this->assertSame(0, $second->wait(), $second->getErrorOutput().$second->getOutput());
            $db = new \PDO('sqlite:'.$path);
            $this->assertSame(['status' => 'sent', 'attempts' => 1], $db->query('SELECT status, attempts FROM service_request_mail_deliveries')->fetch(\PDO::FETCH_ASSOC));
            $this->assertSame('sent', $db->query('SELECT mail_status FROM service_requests')->fetchColumn());
        } finally {
            $first->stop(1);
            $second->stop(1);
        }
    }

    public function test_sigterm_finishes_current_work_and_exits_successfully(): void
    {
        if (! function_exists('pcntl_signal')) {
            $this->markTestSkipped('Signal handling requires pcntl, installed in the production image.');
        }
        $path = $this->fixture();
        $process = $this->worker($path, true);
        try {
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            $process->signal(SIGTERM);
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $db = new \PDO('sqlite:'.$path);
            $this->assertSame('sent', $db->query('SELECT status FROM service_request_mail_deliveries')->fetchColumn());
        } finally {
            $process->stop(1);
        }
    }
}
