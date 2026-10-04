<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LoginSession;
use App\Services\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminAuthController extends Controller
{
    public function login(Request $request, UserRole $roles, LoginSession $sessions)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
        ]);

        $credentials = [
            'email' => strtolower(trim($validated['email'])),
            'password' => $validated['password'],
        ];

        $session = $sessions->attempt($credentials, $request->boolean('remember'));
        // JWTAuth::attempt() already verified the password. Resolve the user explicitly
        // instead of relying on the application's default (possibly web) guard.
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($session === false || ! $user instanceof User || ! $roles->isSuperadmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Неверные учётные данные или недостаточно прав.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'success' => true,
            ...$session,
        ]);
    }
}
