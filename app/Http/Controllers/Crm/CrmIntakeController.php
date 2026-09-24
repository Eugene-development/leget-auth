<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\FormMailDelivery;
use App\Services\FormSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CrmIntakeController extends Controller
{
    public function offline(Request $r, string $site, CrmAccess $access, FormSubmissionService $forms)
    {
        $license = $access->site($r->user(), $site);
        $v = $r->validate([
            'submission_key' => 'required|uuid', 'name' => 'required|string|max:255',
            'phone' => 'nullable|required_without:email|string|max:50', 'email' => 'nullable|required_without:phone|email|max:255',
            'message' => 'nullable|string|max:2000', 'city' => 'nullable|string|max:100',
            'channel' => 'required|in:call,visit,email,referral,other',
            'service_type' => ['required', Rule::in(array_keys(config('forms.types')))],
        ]);
        abort_if(in_array($v['service_type'], ['manager-access', 'partner-application']), 422);
        $channel = $v['channel'];
        unset($v['channel']);
        $r->attributes->set('crm_site', $license);
        $r->attributes->set('crm_channel', $channel);
        $r->attributes->set('crm_actor', $r->user()->id);
        $row = DB::transaction(function () use ($r, $site, $access, $forms, $v) {
            DB::table('licenses')->where('id', $site)->lockForUpdate()->first();
            $access->site($r->user()->fresh(), $site);

            return $forms->accept($v + ['form_id' => 'crm-offline', 'form_title' => 'Офлайн-заявка'], $r);
        }, 3);

        return $this->deliver($row->id);
    }

    public function apply(Request $r, string $site, FormSubmissionService $forms)
    {
        $v = $r->validate(['submission_key' => 'required|uuid', 'message' => 'nullable|string|max:2000']);
        $license = DB::table('licenses')->where('id', $site)->first();
        abort_unless($license, 404);
        abort_if((int) $license->user_id === (int) $r->user()->id, 422, 'Владелец уже имеет доступ.');
        $r->attributes->set('crm_site', $license);
        $r->attributes->set('crm_actor', $r->user()->id);
        $row = DB::transaction(function () use ($r, $site, $forms, $v) {
            DB::table('licenses')->where('id', $site)->lockForUpdate()->first();
            $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($user->role, [Role::Client, Role::Manager]), 409, 'Заявка менеджера доступна клиентам и менеджерам. Текущая роль сохранена.');
            $membership = DB::table('crm_memberships')->where('license_id', $site)->where('user_id', $user->id)->lockForUpdate()->first();
            $retry = DB::table('service_requests')->where('submission_key', $v['submission_key'])->exists();
            abort_if(! $retry && $membership && $membership->status === 'pending', 409, 'Запрос уже ожидает решения владельца.');
            abort_if(! $retry && $membership && $membership->status === 'approved', 409, 'Доступ уже одобрен.');
            $row = $forms->accept($v + ['service_type' => 'manager-access', 'form_id' => 'crm-manager-access', 'form_title' => 'Запрос доступа менеджера', 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? ''], $r);
            if (! $retry && (! $membership || in_array($membership->status, ['rejected', 'revoked']))) {
                $data = ['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null, 'updated_at' => now()];
                if ($membership) {
                    DB::table('crm_memberships')->where('id', $membership->id)->update($data + ['version' => $membership->version + 1]);
                } else {
                    DB::table('crm_memberships')->insert($data + ['id' => (string) Str::ulid(), 'license_id' => $site, 'user_id' => $user->id, 'created_at' => now(), 'version' => 1]);
                }
                DB::table('crm_events')->insert(['id' => (string) Str::ulid(), 'license_id' => $site, 'entity_type' => 'members', 'entity_id' => (string) $user->id, 'actor_id' => $user->id, 'action' => 'access_requested', 'data' => json_encode(['request_id' => $row->id]), 'created_at' => now()]);
            }

            return $row;
        }, 3);

        return $this->deliver($row->id);
    }

    public function attachment(Request $r, string $site, string $id, CrmAccess $access)
    {
        $access->site($r->user(), $site);
        $file = DB::table('service_request_attachments')->where('id', $id)->first();
        abort_unless($file, 404);
        $submission = DB::table('service_requests')->where('id', $file->service_request_id)->where('license_id', $site)->first();
        abort_unless($submission, 404);
        if ($submission->service_type === 'manager-access') {
            $access->site($r->user(), $site, true);
        }
        $bytes = Crypt::decryptString(Storage::disk($file->disk)->get($file->path));

        return response()->streamDownload(fn () => print ($bytes), $file->name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function deliver(string $id)
    {
        app(FormMailDelivery::class)->deliver($id);
        $row = DB::table('service_requests')->where('id', $id)->first();

        return response()->json(['success' => true, 'id' => $id, 'status' => $row->status, 'mail_status' => $row->mail_status, 'message' => 'Заявка принята и сохранена.'], $row->mail_status === 'sent' ? 200 : 202);
    }
}
