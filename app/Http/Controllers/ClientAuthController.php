<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\SmartCaptchaService;
use App\Services\UserProfile;
use App\Services\UserRole;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

            // Проверку роли делаем ДО валидации — так же, как в login() до
            // attempt(). Раньше порядок был обратный и это работало: роль жила
            // в allowlist, пользователя с таким адресом могло не быть вовсе.
            // Теперь админ — строка в users, его адрес занят, и правило
            // `unique:users` ответило бы «email уже занят» вместо «это адрес
            // суперадминистратора» — человек не понял бы, что входить надо в /admin.
            $submittedEmail = $request->input('email');

            if (is_string($submittedEmail) && $roles->isSuperadminEmail($submittedEmail)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Этот адрес принадлежит суперадминистратору платформы.',
                    'errors' => ['email' => ['Этот адрес принадлежит суперадминистратору платформы.']],
                ], Response::HTTP_FORBIDDEN);
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

            $user = User::create([
                'name' => $validated['name'],
                'email' => $email,
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'region' => $validated['region'] ?? null,
            ]);

            try {
                $user->notify(new VerifyEmailNotification());
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
                'role' => UserRole::CLIENT,
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

    public function login(Request $request, SmartCaptchaService $captcha, UserRole $roles, UserProfile $profiles)
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
            ]);

            $credentials = [
                'email' => strtolower(trim($validated['email'])),
                'password' => $validated['password'],
            ];

            // Проверку роли делаем ДО attempt: админу не выдаётся клиентский
            // токен даже при верном пароле.
            if ($roles->isSuperadminEmail($credentials['email'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Суперадминистратор входит через панель /admin.',
                    'role' => UserRole::SUPERADMIN,
                ], Response::HTTP_FORBIDDEN);
            }

            $token = JWTAuth::attempt($credentials);
            // attempt() уже проверил пароль; пользователя достаём явно, не
            // полагаясь на guard по умолчанию (он может быть web).
            $user = User::query()->where('email', $credentials['email'])->first();

            if (! is_string($token) || ! $user instanceof User) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный email или пароль.',
                    'errors' => ['email' => ['Неверный email или пароль.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'success' => true,
                'user' => $profiles->for($user),
                'role' => UserRole::CLIENT,
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
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
