<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Exception;

class AuthController extends Controller
{
    /**
     * Register a new user and send email verification.
     */
    public function register(Request $request)
    {
        try {
            Log::info('LEGET: Registration attempt', [
                'name'  => $request->input('name'),
                'email' => $request->input('email'),
            ]);

            $request->validate([
                'name'                  => 'required|string|max:255',
                'email'                 => 'required|string|email|max:255|unique:users',
                'password'              => 'required|string|min:8|confirmed',
                'phone'                 => 'nullable|string|max:30',
            ]);

            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'phone'    => $request->phone,
            ]);

            // Send email verification notification
            try {
                $user->notify(new VerifyEmailNotification());
            } catch (Exception $e) {
                Log::error('LEGET: Failed to send verification email', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Generate JWT token
            $token = JWTAuth::fromUser($user);
            $ttl   = JWTAuth::factory()->getTTL();

            Log::info('LEGET: Registration successful', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);

            return response()->json([
                'success'    => true,
                'user'       => $user->fresh(),
                'token'      => $token,
                'token_type' => 'bearer',
                'expires_in' => $ttl * 60,
                'message'    => 'Регистрация успешна. Проверьте почту для подтверждения email.',
            ], Response::HTTP_CREATED);
        } catch (ValidationException $e) {
            Log::warning('LEGET: Registration validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors'  => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JWTException $e) {
            Log::error('LEGET: JWT token creation error after registration', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Пользователь создан, но не удалось выдать токен. Попробуйте войти.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            Log::error('LEGET: Registration error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при регистрации.',
                'errors'  => ['general' => ['Произошла непредвиденная ошибка. Попробуйте позже.']],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Login user and return JWT token.
     */
    public function login(Request $request)
    {
        try {
            Log::info('LEGET: Login attempt', ['email' => $request->input('email')]);

            $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            $credentials = $request->only('email', 'password');

            if (!$token = JWTAuth::attempt($credentials)) {
                Log::warning('LEGET: Login failed - invalid credentials', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный email или пароль.',
                    'errors'  => ['email' => ['Неверный email или пароль.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $user = Auth::user();
            $ttl  = JWTAuth::factory()->getTTL();

            Log::info('LEGET: Login successful', ['user_id' => $user->id, 'email' => $user->email]);

            return response()->json([
                'success'          => true,
                'user'             => $user,
                'token'            => $token,
                'token_type'       => 'bearer',
                'expires_in'       => $ttl * 60,
                'email_verified'   => $user->hasVerifiedEmail(),
                'message'          => 'Вход выполнен успешно.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Некорректные данные.',
                'errors'  => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JWTException $e) {
            Log::error('LEGET: JWT login error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Не удалось создать токен авторизации.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            Log::error('LEGET: Login error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при входе в систему.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Logout user (invalidate JWT token).
     */
    public function logout(Request $request)
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json(['success' => true, 'message' => 'Выход выполнен успешно.']);
        } catch (JWTException $e) {
            Log::error('LEGET: Logout error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Не удалось выполнить выход.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не аутентифицирован.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Получаем баланс кошелька пользователя
            $wallet = $user->wallet;
            $balance = $wallet ? $wallet->balance : '0.00';

            return response()->json([
                'success'        => true,
                'user'           => $user,
                'email_verified' => $user->hasVerifiedEmail(),
                'balance'        => $balance,
            ]);
        } catch (Exception $e) {
            Log::error('LEGET: Get user error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения данных пользователя.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify email address.
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        try {
            Log::info('LEGET: Email verification attempt', ['id' => $id, 'hash' => $hash]);

            $user          = User::findOrFail($id);
            $expectedHash  = sha1($user->getEmailForVerification());

            if (!hash_equals((string) $hash, $expectedHash)) {
                Log::warning('LEGET: Email verification failed - hash mismatch', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Недействительная ссылка подтверждения.',
                ], Response::HTTP_FORBIDDEN);
            }

            if ($user->hasVerifiedEmail()) {
                Log::info('LEGET: Email already verified', ['id' => $id]);
                return response()->json([
                    'success' => true,
                    'message' => 'Email уже был подтверждён ранее.',
                    'user'    => $user,
                ]);
            }

            $user->markEmailAsVerified();
            event(new \Illuminate\Auth\Events\Verified($user));

            Log::info('LEGET: Email verified successfully', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Email успешно подтверждён. Теперь вы можете войти в личный кабинет.',
                'user'    => $user->fresh(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('LEGET: Email verification - user not found', ['id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден.',
            ], Response::HTTP_NOT_FOUND);
        } catch (Exception $e) {
            Log::error('LEGET: Email verification error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при подтверждении email.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resend email verification link.
     */
    public function resendVerification(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email уже подтверждён.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $user->notify(new VerifyEmailNotification());

            return response()->json([
                'success' => true,
                'message' => 'Письмо с подтверждением отправлено повторно.',
            ]);
        } catch (Exception $e) {
            Log::error('LEGET: Resend verification error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Не удалось отправить письмо подтверждения.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
