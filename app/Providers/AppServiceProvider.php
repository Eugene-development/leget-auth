<?php

namespace App\Providers;

use App\Auth\VersionedJwtGuard;
use App\Enums\Role;
use App\Models\User;
use App\Services\UniversityVerificationIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('versioned-jwt', function ($app, string $name, array $config): VersionedJwtGuard {
            $guard = new VersionedJwtGuard($app['tymon.jwt'], $app['auth']->createUserProvider($config['provider']), $app['request']);
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        $this->configureRateLimiting();
        $this->registerRoleGates();
    }

    /**
     * Способности ролей → Gate, чтобы маршруты закрывались штатным `can:`.
     *
     * Права берутся из кода (`Role::abilities()`), а не из таблицы: проверку
     * всё равно пишет разработчик вместе с маршрутом, и таблица прав была бы
     * вторым источником правды, расходящимся с routes/api.php.
     *
     * Проверять надо способность, а не имя роли — тогда доступ второй роли
     * добавляется строкой в enum, а не поиском по коду.
     */
    private function registerRoleGates(): void
    {
        foreach (Role::abilityNames() as $ability) {
            Gate::define($ability, static fn (User $user): bool => $user->hasAbility($ability));
        }
    }

    /**
     * Лимит клиентских auth-запросов.
     *
     * Ключ — email, а не IP: `/api/client/*` вызывает серверная часть leget-main,
     * и IP у всех посетителей один (адрес контейнера). Лимит по IP заблокировал
     * бы вход сразу всему сайту после пяти чужих попыток; лимит по email бьёт
     * ровно по перебору пароля конкретного аккаунта. IP остаётся запасным ключом
     * для запросов вовсе без email.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('password-recovery', function (Request $request): array {
            $email = $request->input('email');
            $key = hash('sha256', is_string($email) ? strtolower(trim($email)) : 'invalid');

            return [
                Limit::perMinute(5)->by('password-recovery:minute:'.$key),
                Limit::perHour(10)->by('password-recovery:hour:'.$key),
                Limit::perMinute(30)->by('password-recovery:ip-minute:'.$request->ip()),
                Limit::perHour(100)->by('password-recovery:ip-hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('university-verification', function (Request $request): array {
            $ip = app(UniversityVerificationIp::class)->resolve($request);
            $key = 'university-verification:'.$ip;

            return [Limit::perMinute(10)->by($key.':minute'), Limit::perHour(100)->by($key.':hour')];
        });

        RateLimiter::for('client-auth', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email')));

            return $email !== ''
                ? Limit::perMinute(10)->by('client-auth:'.$email)
                : Limit::perMinute(10)->by('client-auth:'.$request->ip());
        });
    }
}
