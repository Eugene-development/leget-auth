<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «Кто я» — профиль вошедшего пользователя платформы любой роли.
 *
 * Закрыт только `auth:api` и отвечает всем вошедшим, включая партнёра: страницам
 * leget-main нужно понять, чей кабинет показывать, ещё до того как выяснится,
 * какая роль пришла. Границей доступа этот ответ не является — её держат
 * способности на маршрутах данных.
 *
 * Заменил `/api/client/me`, закрытый `can:cabinet.view`: тот отвечал только
 * клиенту и партнёру возвращал 403.
 *
 * Роль здесь читается из БД, а не из claim'а токена: claim — снимок на момент
 * выдачи, и после смены роли он врёт до истечения TTL. Claim годится для
 * отрисовки шапки, решение о доступе принимает этот ответ.
 */
final class SessionController extends Controller
{
    public function me(Request $request, UserProfile $profiles)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не аутентифицирован.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'success' => true,
            'user' => $profiles->for($user),
        ]);
    }
}
