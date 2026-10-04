<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\LoginSession;
use App\Services\SmartCaptchaService;
use App\Services\UserProfile;
use App\Services\UserRole;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Регистрация и вход клиента сайта (роль «Клиент»).
 *
 * Отдельный контроллер, а не флаг в AuthController: у клиента другая граница
 * доступа. `/api/auth/*` обслуживает владельца сайта (ЛК тенанта в leget-front
 * и режим правки в leget-main), `/api/client/*` — посетителя, который завёл
 * личный кабинет на сайте тенанта. Админам платформы вход сюда закрыт —
 * см. App\Enums\Role и способность `cabinet.view`.
 *
 * Токен браузеру не отдаётся напрямую: эти endpoint'ы вызывает серверная часть
 * leget-main и кладёт JWT в httpOnly-cookie. Поэтому клиентский вход не может
 * включить режим редактирования сайта (он живёт в localStorage владельца).
 */
final class ClientAuthController extends Controller
{
    public function register(Request $request, SmartCaptchaService $captcha, UserRole $roles, UserProfile $profiles)
    {
        try {
            if (! $captcha->verify($request->input('captcha_token'), $this->visitorIp($request, $captcha))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не пройдена проверка безопасности.',
                    'errors' => ['captcha_token' => ['Подтвердите, что вы не робот.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
            $request->validate(['email' => 'required|string|email|max:255']);
            if (User::whereRaw('LOWER(email) = ?', [$request->input('email')])->exists()) {
                return response()->json(['success' => false, 'code' => 'account_exists',
                    'message' => 'Аккаунт уже существует. Войдите с прежним паролем, чтобы использовать его.'], 409);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
                'phone' => 'nullable|string|max:30',
                'region' => 'nullable|string|max:120',
                'captcha_token' => 'nullable|string',
            ]);

            $email = strtolower(trim($validated['email']));

            $user = DB::transaction(function () use ($validated, $email, $request) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $email,
                    'password' => Hash::make($validated['password']),
                    'phone' => $validated['phone'] ?? null,
                    'region' => $validated['region'] ?? null,
                ]);

                if ($request->routeIs('university.register')) {
                    $user->forceFill(['role' => Role::Student, 'university_enrolled_at' => now()])->save();
                }

                return $user;
            });

            try {
                $user->notify(new VerifyEmailNotification);
            } catch (Exception $e) {
                // Письмо — не условие регистрации: кабинет уже доступен,
                // подтверждение можно запросить повторно.
                Log::error('LEGET: Failed to send client verification email', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $token = JWTAuth::fromUser($user);

            Log::info('LEGET: Client registration successful', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'user' => $profiles->for($user->fresh()),
                'role' => $roles->of($user),
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
                'message' => 'Регистрация успешна. Проверьте почту для подтверждения email.',
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JWTException $e) {
            Log::error('LEGET: Client JWT creation error after registration', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Аккаунт создан, но не удалось выдать токен. Попробуйте войти.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            Log::error('LEGET: Client registration error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при регистрации.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function login(Request $request, SmartCaptchaService $captcha, UserRole $roles, UserProfile $profiles, LoginSession $sessions)
    {
        try {
            if (! $captcha->verify($request->input('captcha_token'), $this->visitorIp($request, $captcha))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не пройдена проверка безопасности.',
                    'errors' => ['captcha_token' => ['Подтвердите, что вы не робот.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validated = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
                'captcha_token' => 'nullable|string',
                'remember' => 'sometimes|boolean',
            ]);

            $credentials = [
                'email' => strtolower(trim($validated['email'])),
                'password' => $validated['password'],
            ];

            $existing = User::whereRaw('LOWER(email) = ?', [$credentials['email']])->first();
            if ($existing) {
                $credentials['email'] = $existing->email;
            }
            $session = $sessions->attempt($credentials, $request->boolean('remember'));
            // attempt() уже проверил пароль; пользователя достаём явно, не
            // полагаясь на guard по умолчанию (он может быть web).
            $user = User::query()->where('email', $credentials['email'])->first();

            if ($session === false || ! $user instanceof User) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный email или пароль.',
                    'errors' => ['email' => ['Неверный email или пароль.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (! $request->routeIs('university.login') && $roles->isSuperadmin($user)) {
                return response()->json(['success' => false, 'message' => 'Суперадминистратор входит через панель /admin или форму университета.'], 403);
            }

            return response()->json([
                'success' => true,
                'user' => $profiles->for($user),
                'role' => $roles->of($user),
                ...$session,
                'token_type' => 'bearer',
                'message' => 'Вход выполнен успешно.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JWTException $e) {
            Log::error('LEGET: Client JWT login error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось создать токен авторизации.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * IP посетителя для оценки риска в SmartCaptcha.
     *
     * Запрос приходит от серверной части leget-main, поэтому `$request->ip()`
     * здесь — адрес контейнера, один на всех. Настоящий адрес leget-main кладёт
     * в `X-Forwarded-For`. Это подсказка для скоринга Яндекса, а не граница
     * доступа: подделанный заголовок ухудшает оценку только самому отправителю,
     * решение о допуске принимает верификация одноразового токена капчи.
     */
    private function visitorIp(Request $request, SmartCaptchaService $captcha): ?string
    {
        $forwarded = trim(explode(',', (string) $request->header('X-Forwarded-For'))[0]);

        return $forwarded !== '' ? $forwarded : $captcha->clientIp($request);
    }
}
