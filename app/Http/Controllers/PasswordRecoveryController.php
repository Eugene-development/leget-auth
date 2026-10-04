<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PasswordRecovery;
use App\Services\SmartCaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PasswordRecoveryController extends Controller
{
    public function forgot(Request $request, PasswordRecovery $recovery, SmartCaptchaService $captcha): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'captcha_token' => ['nullable', 'string', 'max:4096'],
        ], [
            'email.required' => 'Укажите email аккаунта.',
            'email.string' => 'Укажите корректный email.',
            'email.email' => 'Укажите корректный email.',
            'email.max' => 'Email должен содержать не более 255 символов.',
            'captcha_token.string' => 'Повторите проверку безопасности.',
            'captcha_token.max' => 'Повторите проверку безопасности.',
        ]);

        if (! $captcha->verify($data['captcha_token'] ?? null, $captcha->clientIp($request))) {
            return response()->json([
                'success' => false,
                'message' => 'Не пройдена проверка безопасности.',
                'errors' => ['captcha_token' => ['Подтвердите, что вы не робот.']],
            ], 422);
        }

        try {
            $recovery->sendLink(strtolower(trim($data['email'])));
        } catch (Throwable $e) {
            // No token is issued in HTTP. A failed request enqueue permits a retry.
            // Never log exception text/trace: queue payloads contain private data.
            Log::error('Password recovery link could not be queued.', ['exception' => $e::class]);
        }

        // The same answer covers unknown accounts, broker cooldown and queue errors.
        return response()->json([
            'success' => true,
            'message' => 'Если аккаунт с таким email существует, мы отправим письмо со ссылкой для восстановления пароля. Если письмо не пришло, проверьте спам и попробуйте позже.',
        ]);
    }

    public function reset(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['bail', 'required', 'string', 'min:8', 'max:72', 'confirmed',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen($value) > 72 || str_contains($value, "\0")) {
                        $fail('Пароль должен занимать не более 72 байт и не содержать нулевых символов.');
                    }
                }],
        ], [
            'email.required' => 'Укажите email аккаунта.',
            'email.string' => 'Укажите корректный email.',
            'email.email' => 'Укажите корректный email.',
            'email.max' => 'Email должен содержать не более 255 символов.',
            'token.required' => 'Откройте ссылку из письма восстановления.',
            'token.string' => 'Ссылка восстановления недействительна.',
            'token.max' => 'Ссылка восстановления недействительна.',
            'password.required' => 'Укажите новый пароль.',
            'password.string' => 'Укажите новый пароль.',
            'password.min' => 'Пароль должен содержать не менее 8 символов.',
            'password.max' => 'Пароль должен содержать не более 72 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
        ]);
        $data['email'] = strtolower(trim($data['email']));

        try {
            $reset = $recovery->reset($data);
        } catch (Throwable $e) {
            Log::error('Password recovery could not reset password.', ['exception' => $e::class]);

            return response()->json(['success' => false, 'message' => 'Не удалось изменить пароль. Попробуйте позже.'], 503);
        }

        if (! $reset) {
            $message = 'Ссылка восстановления недействительна или истекла. Запросите новое письмо.';

            return response()->json([
                'success' => false,
                'code' => 'invalid_reset_token',
                'message' => $message,
                'errors' => ['token' => [$message]],
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Пароль изменён. Войдите в свой аккаунт с новым паролем.']);
    }
}
