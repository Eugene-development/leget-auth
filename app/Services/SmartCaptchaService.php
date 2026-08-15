<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Серверная верификация токена Yandex SmartCaptcha.
 *
 * Токен, полученный на фронте (виджет капчи), проверяется здесь через
 * официальный эндпоинт Яндекса. Только после успешной проверки запрос
 * считается поданным живым пользователем.
 *
 * Логика ответа Яндекса:
 *  - HTTP 200 + {"status": "ok"}            → токен валиден.
 *  - HTTP 200 + {"status": "failed", ...}   → проверка не пройдена.
 *  - HTTP 4xx (например, 403 при невалидном/просроченном токене) → не пройдена.
 *
 * Режим fail-open: если секрет не задан (стейджинг/локалка), верификация
 * пропускается и возвращается true. Это сознательное решение — не ломать
 * отправку форм при отсутствии ключа, защита включается автоматически
 * как только ключ прописан в окружении.
 *
 * @see https://yandex.cloud/en/docs/smartcaptcha/concepts/validation
 */
class SmartCaptchaService
{
    /**
     * Проверить токен капчи.
     *
     * @param  string|null $token Токен из запроса (captcha_token).
     * @param  string|null $ip    IP посетителя (для аналитики Яндекса, опционально).
     * @return bool               true — проверка пройдена (или fail-open).
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = config('services.smartcaptcha.secret');

        // Fail-open: без секретного ключа защита не активна.
        // Это позволяет не ломать dev/staging, где ключ может быть не прописан.
        if (empty($secret)) {
            Log::info('SmartCaptcha: secret key not set, skipping verification (fail-open).');
            return true;
        }

        // Нет токена — не пройдено. Это блокирует прямые запросы без капчи.
        if (empty($token)) {
            return false;
        }

        $serverUrl = config('services.smartcaptcha.server_url');

        $payload = [
            'secret' => $secret,
            'token' => $token,
        ];
        // IP опционален, но улучшает оценку риска на стороне Яндекса.
        if ($ip) {
            $payload['ip'] = $ip;
        }

        try {
            $response = Http::asForm()->timeout(5)->post($serverUrl, $payload);

            // 4xx — невалидный/просроченный токен и т.п. Явный отказ.
            if (!$response->successful()) {
                Log::warning('SmartCaptcha: verification HTTP error', [
                    'status' => $response->status(),
                ]);
                return false;
            }

            $data = $response->json();

            return ($data['status'] ?? null) === 'ok';
        } catch (\Throwable $e) {
            // Сетевой сбой / таймаут: отказываем, чтобы не пропустить бота при
            // недоступности сервиса, но логируем для расследования.
            Log::error('SmartCaptcha: verification request failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Извлекает IP клиента из запроса с учётом доверенных прокси.
     * Запросы приходят через CDN/гейтвей, поэтому реальный IP — в X-Forwarded-For.
     */
    public function clientIp($request): ?string
    {
        // Учитываем доверенные proxy (TrustProxies middleware в Laravel).
        $ip = $request->ip();
        return $ip ?: null;
    }
}
