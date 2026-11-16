<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class EngagementTrackingController extends Controller
{
    public function track(Request $request)
    {
        $header = $request->header('X-Leget-Engagement-Context', '');
        if (strlen($header) > 2048) {
            return $this->reject('invalid_context', 403);
        }
        [$payload, $signature] = array_pad(explode('.', $header, 2), 2, '');
        $secret = config('forms.context_secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            return response()->json(['allowed' => false], 503)->header('Cache-Control', 'no-store');
        }
        if (! hash_equals(hash_hmac('sha256', 'leget-engagement:v1:'.$payload, $secret), $signature)) {
            return $this->reject('invalid_context', 403);
        }
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);
        if (! is_array($data)
            || ! is_int($data['timestamp'] ?? null) || abs(time() - $data['timestamp']) > 60
            || ! is_string($data['domain'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9.-]{0,252}$/D', $data['domain'])
            || ! is_string($data['session'] ?? null) || ! preg_match('/^[a-f0-9-]{36}$/D', $data['session'])
            || ! is_string($data['goal'] ?? null) || ! preg_match('/^(promo_[123]|test)_[a-z0-9_]{1,240}$/D', $data['goal'])
            || ! is_string($data['ip'] ?? null) || ! filter_var($data['ip'], FILTER_VALIDATE_IP)) {
            return $this->reject('invalid_context', 403);
        }

        $scope = hash_hmac('sha256', $data['domain'], $secret);
        $sessionKey = 'engagement:session:'.$scope.':'.hash('sha256', $data['session']);
        $ipKey = 'engagement:ip:'.$scope.':'.hash_hmac('sha256', $data['ip'], $secret);
        // Serialize check+increment across workers. The shared cache also owns the counters.
        $sessionLock = Cache::lock('engagement:lock:'.$sessionKey, 5);
        $ipLock = Cache::lock('engagement:lock:'.$ipKey, 5);
        try {
            $sessionLock->block(1);
            $ipLock->block(1);
            if (RateLimiter::tooManyAttempts($sessionKey, 30)) {
                return $this->reject('session_limit', 429, $data['goal']);
            }
            if (RateLimiter::tooManyAttempts($ipKey, 120)) {
                return $this->reject('ip_limit', 429, $data['goal']);
            }
            RateLimiter::hit($sessionKey, 60);
            RateLimiter::hit($ipKey, 60);
        } catch (LockTimeoutException) {
            return $this->reject('busy', 429, $data['goal']);
        } finally {
            $ipLock->release();
            $sessionLock->release();
        }

        return response()->json(['allowed' => true])->header('Cache-Control', 'no-store');
    }

    private function reject(string $reason, int $status, ?string $goal = null)
    {
        Log::info('engagement_rejected', array_filter(['reason' => $reason, 'goal' => $goal]));
        $response = response()->json(['allowed' => false], $status)->header('Cache-Control', 'no-store');

        return $status === 429 ? $response->header('Retry-After', '60') : $response;
    }
}
