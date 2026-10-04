<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LoginSession;
use App\Services\SmartCaptchaService;
use App\Services\SmsLogin;
use App\Services\UserProfile;
use App\Services\UserRole;
use Illuminate\Http\Request;

final class SmsAuthController extends Controller
{
    public function request(Request $request, SmsLogin $sms, SmartCaptchaService $captcha)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:30', 'context' => 'required|in:owner,client,admin,university',
            'captcha_token' => 'nullable|string|max:4096',
        ]);
        $sms->available();
        abort_unless($captcha->verify($data['captcha_token'] ?? null, $captcha->clientIp($request)), 422, 'Подтвердите, что вы не робот.');

        return response()->json($sms->request($data['phone'], $data['context']))->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, SmsLogin $sms, UserRole $roles, UserProfile $profiles, LoginSession $sessions)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:30', 'context' => 'required|in:owner,client,admin,university',
            'challenge_id' => 'required|uuid', 'code' => 'required|string|regex:/^[0-9]{6}$/',
            'remember' => 'sometimes|boolean',
        ]);
        $user = $sms->verify($data['phone'], $data['challenge_id'], $data['code'], $data['context']);
        abort_if($data['context'] === 'admin' && ! $roles->isSuperadmin($user), 403, 'Недостаточно прав для панели администратора.');
        abort_if($data['context'] === 'client' && $roles->isSuperadmin($user), 403, 'Войдите через панель /admin или университет.');

        return response()->json([
            'success' => true, 'user' => $profiles->for($user), 'role' => $roles->of($user),
            ...$sessions->forUser($user, $request->boolean('remember')), 'token_type' => 'bearer',
            'email_verified' => $user->hasVerifiedEmail(),
        ])->header('Cache-Control', 'no-store');
    }
}
