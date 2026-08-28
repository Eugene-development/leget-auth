<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

final class AdminAuthController extends Controller
{
    public function login(Request $request, UserRole $roles)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = [
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
        ];

        $token = JWTAuth::attempt($credentials);
        // JWTAuth::attempt() already verified the password. Resolve the user explicitly
        // instead of relying on the application's default (possibly web) guard.
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! is_string($token) || ! $user instanceof User || ! $roles->isSuperadmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Неверные учётные данные или недостаточно прав.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'success' => true,
            'token' => $token,
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ]);
    }
}
