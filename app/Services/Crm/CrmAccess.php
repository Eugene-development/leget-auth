<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Kept identical in auth/API: the same policy protects intake, files and CRM. */
final class CrmAccess
{
    public function site(User $user, string $id, bool $manage = false): object
    {
        $site = DB::table('licenses')->where('id', $id)->first();
        abort_unless($site, 404, 'Сайт не найден.');
        if ($user->role?->can('crm.admin') || (int) $site->user_id === (int) $user->id) {
            return $site;
        }
        abort_if($manage, 403, 'Настройки доступны владельцу сайта.');
        abort_unless($user->role?->can('crm.work') && DB::table('crm_memberships')
            ->where('license_id', $id)->where('user_id', $user->id)->where('status', 'approved')->exists(), 403, 'Нет доступа к CRM этого сайта.');

        return $site;
    }

    public function manages(User $user, object $site): bool
    {
        return $user->role?->can('crm.admin') || (int) $site->user_id === (int) $user->id;
    }

    public function assignee(string $site, ?int $id): void
    {
        if ($id === null) {
            return;
        }
        $user = User::find($id);
        abort_unless($user, 422, 'Ответственный не найден.');
        $this->site($user, $site);
    }
}
