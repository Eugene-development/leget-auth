<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAccess;
use App\Services\FormMailDelivery;
use App\Services\FormSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
