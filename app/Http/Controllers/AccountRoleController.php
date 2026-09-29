<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

final class AccountRoleController extends Controller
{
    public function client(Request $request, UserProfile $profiles)
    {
        return $this->activate($request, Role::Client, $profiles);
    }

    public function owner(Request $request, UserProfile $profiles)
    {
        return $this->activate($request, Role::Admin, $profiles);
    }

    private function activate(Request $request, Role $target, UserProfile $profiles)
    {
        $user = DB::transaction(function () use ($request, $target) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, [Role::Student, $target], true), 409,
                'У аккаунта уже есть другая основная роль. Регистрация её не заменяет.');
            $user->setPrimaryRole($target)->save();
            if ($target === Role::Admin) {
                Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => '0.00']);
            }

            return $user;
        }, 3);

        return response()->json(['success' => true, 'user' => $profiles->for($user),
            'token' => JWTAuth::fromUser($user), 'expires_in' => JWTAuth::factory()->getTTL() * 60]);
    }
}
