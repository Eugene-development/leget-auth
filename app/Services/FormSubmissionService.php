<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Growth\GrowthFormDetails;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FormSubmissionService
{
    /** Persist the outbox and its attachments before attempting any email. */
    public function accept(array $data, Request $request): object
    {
        $site = app(FormSiteContext::class)->resolve($request);
        $channel = $request->attributes->get('crm_channel', 'online');
        $actor = $request->attributes->get('crm_actor');
        $files = [];
        foreach (['passport_main', 'passport_registration', 'client_photo'] as $field) {
            if (($data[$field] ?? null) instanceof UploadedFile) {
                $files[$field] = $data[$field];
            }
        }
        foreach ($data['photos'] ?? [] as $i => $file) {
            $files['warranty-photo-'.($i + 1)] = $file;
        }
        unset($data['captcha_token'], $data['passport_main'], $data['passport_registration'], $data['client_photo'], $data['photos']);
        $key = $data['submission_key'] ?? (string) Str::uuid();
        unset($data['submission_key']);
        ksort($data);
        $hashData = $data;
        if ($site) {
            $hashData['_license_id'] = $site->id;
        }
        if ($actor) {
            $hashData['_actor_id'] = $actor;
        }
        if ($channel !== 'online') {
            $hashData['_channel'] = $channel;
        }
        foreach ($files as $field => $file) {
            $hashData['file:'.$field] = hash_file('sha256', $file->getRealPath());
        }
        $hash = hash('sha256', json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($existing = DB::table('service_requests')->where('submission_key', $key)->first()) {
            return $this->existing($existing, $hash);
        }

        $id = (string) Str::ulid();
        $stored = [];
        try {
            foreach ($files as $field => $file) {
                $disk = config('forms.attachment_disk');
                $path = 'form-submissions/'.$id.'/'.Str::ulid().'.enc';
                $stored[] = [$disk, $path];
                if (! Storage::disk($disk)->put($path, Crypt::encryptString(file_get_contents($file->getRealPath())), ['visibility' => 'private'])) {
                    throw new \RuntimeException('Attachment storage unavailable');
                }
            }

            return DB::transaction(function () use ($data, $request, $key, $hash, $id, $files, $stored, $site, $channel, $actor) {
                $source = $data['source_url'] ?? null;
                $domain = strtolower(preg_replace('/^www\./i', '', parse_url($source ?? '', PHP_URL_HOST) ?: ''));
                if ($site) {
                    $domain = $site->domain;
                }
                $recipient = config('forms.recipient_override') ?: config('forms.recipient');
                if (! config('forms.recipient_override') && $domain) {
                    $owner = $site ? DB::table('users')->where('id', $site->user_id)->value('email') : DB::table('licenses')->join('users', 'users.id', '=', 'licenses.user_id')->where('licenses.domain', $domain)->value('users.email');
                    if ($owner) {
                        $recipient = $owner;
                    }
                }
                if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Form recipient not configured');
                }
                $type = $data['service_type'];
                $legacy = null;
                if (! $request->filled('submission_key')) {
                    $query = DB::table('service_requests')->where('mail_status', 'legacy')
                        ->where('created_at', '>=', now()->subMinutes(5));
                    foreach (['service_type', 'name', 'phone', 'message', 'source_url', 'city'] as $field) {
                        $query->where($field, $data[$field] ?? null);
                    }
                    $query->where('license_id', $site?->id);
                    $legacy = $query->lockForUpdate()->first();
                    if ($legacy) {
                        $id = $legacy->id;
                    }
                }
                $details = array_intersect_key($data, array_flip(['consent', 'form_title', 'company', 'partnership_status', 'contract_number', 'position', 'partner_type', 'inn', 'website', 'partner_profile_id', 'dimensions', 'object_address', 'visit_time', 'order_id', 'order_title']));
                $details += app(GrowthFormDetails::class)->resolve($data, $site);
                if (isset($details['dimensions']) && is_array($details['dimensions'])) {
                    $details['dimensions'] = array_values($details['dimensions']);
                }
                $row = [
                    'license_id' => $site?->id, 'channel' => $channel, 'created_by' => $actor,
                    'id' => $id, 'submission_key' => $key, 'payload_hash' => $hash,
                    'service_type' => $type, 'form_id' => $data['form_id'] ?? $type,
                    'name' => $data['name'] ?? '', 'phone' => $data['phone'] ?? '',
                    'email' => $data['email'] ?? null, 'message' => $data['message'] ?? null,
                    'city' => $data['city'] ?? null, 'source_url' => $source,
                    'site_domain' => $domain ?: null, 'recipient_email' => $recipient,
                    'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                    'status' => 'new', 'mail_status' => 'pending', 'mail_retry_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                if ($legacy) {
                    $row['created_at'] = $legacy->created_at;
                    DB::table('service_requests')->where('id', $id)->update($row);
                } else {
                    DB::table('service_requests')->insert($row);
                }
                foreach (array_values($files) as $i => $file) {
                    DB::table('service_request_attachments')->insert([
                        'id' => (string) Str::ulid(), 'service_request_id' => $id,
                        'disk' => $stored[$i][0], 'path' => $stored[$i][1],
                        'name' => array_keys($files)[$i].'.'.($file->guessExtension() ?: 'bin'),
                        'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($channel === 'online' && in_array($type, config('forms.conversion_types'), true) && ! DB::table('conversions')->where('service_request_id', $id)->exists()) {
                    DB::table('conversions')->insert([
                        'id' => (string) Str::ulid(), 'channel' => 'online', 'type' => $type,
                        'name' => $row['name'], 'contact' => $row['phone'] ?: $row['email'],
                        'comment' => $row['message'], 'source_url' => $source,
                        'service_request_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                return DB::table('service_requests')->where('id', $id)->first();
            });
        } catch (\Throwable $e) {
            foreach ($stored as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable) { /* Encrypted orphan; no accepted submission. */
                }
            }
            if ($e instanceof UniqueConstraintViolationException && ($existing = DB::table('service_requests')->where('submission_key', $key)->first())) {
                return $this->existing($existing, $hash);
            }
            throw $e;
        }
    }

    private function existing(object $row, string $hash): object
    {
        if (! hash_equals($row->payload_hash, $hash)) {
            throw ValidationException::withMessages(['submission_key' => 'Ключ уже использован для другой заявки.']);
        }

        return $row;
    }
}
