<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmsLogin
{
    public function available(): void
    {
        abort_unless(config('sms.driver') === 'test' && app()->environment(['local', 'testing']), 503,
            'Вход по SMS пока доступен только в тестовом окружении. Используйте пароль.');
    }

    public function normalize(string $phone): string
    {
        if (! preg_match('/^[+\d\s().-]+$/u', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Введите корректный номер телефона.']);
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }
        if (! preg_match('/^[1-9][0-9]{9,14}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => 'Введите номер с кодом страны, например +7 999 123-45-67.']);
        }

        return $digits;
    }

    // Existing profiles contain formatted phone numbers. Ambiguous numbers never log in.
    private function account(string $phone): ?User
    {
        $expression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";
        $variants = [$phone];
        if (strlen($phone) === 11 && $phone[0] === '7') {
            $variants[] = '8'.substr($phone, 1);
        }
        $users = User::query()->whereRaw($expression.' IN ('.implode(',', array_fill(0, count($variants), '?')).')', $variants)->limit(2)->get();

        return $users->count() === 1 ? $users->first() : null;
    }

    public function request(string $phone, string $context): array
    {
        $this->available();
        $phone = $this->normalize($phone);
        $key = 'sms-login:'.hash('sha256', $phone);

        return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $phone, $context) {
            abort_if(RateLimiter::tooManyAttempts($key.':minute', 1) || RateLimiter::tooManyAttempts($key.':hour', 5), 429,
                'Слишком много запросов. Попробуйте позже.');
            RateLimiter::hit($key.':minute', 60);
            RateLimiter::hit($key.':hour', 3600);
            $id = (string) Str::uuid();
            $code = (string) random_int(100000, 999999);
            Cache::put($key, [
                'id' => $id, 'hash' => Hash::make($code), 'context' => $context,
                'user_id' => $this->account($phone)?->id, 'attempts' => 0, 'expires' => now()->timestamp + 300,
            ], 300);

            return ['success' => true, 'challenge_id' => $id, 'expires_in' => 300, 'retry_after' => 60,
                'test_code' => $code, 'test_mode' => true];
        });
    }

    public function verify(string $phone, string $id, string $code, string $context): User
    {
        $this->available();
        $phone = $this->normalize($phone);
        $key = 'sms-login:'.hash('sha256', $phone);

        return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $phone, $id, $code, $context) {
            $challenge = Cache::get($key);
            $fail = fn () => throw ValidationException::withMessages(['code' => 'Код неверен или истёк. Запросите новый код или войдите с паролем.']);
            if (! $challenge || $challenge['expires'] <= now()->timestamp || $challenge['attempts'] >= 5
                || $challenge['id'] !== $id || $challenge['context'] !== $context) {
                $fail();
            }
            $challenge['attempts']++;
            Cache::put($key, $challenge, max(1, $challenge['expires'] - now()->timestamp));
            if (! Hash::check($code, $challenge['hash'])) {
                $fail();
            }
            Cache::forget($key); // Consume before issuing any session, including denied contexts.
            $user = $this->account($phone);
            if (! $user || $user->id !== $challenge['user_id']) {
                $fail();
            }

            return $user;
        });
    }
}
