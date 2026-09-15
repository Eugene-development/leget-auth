<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

final class FormMailDelivery
{
    public function deliver(string $id): void
    {
        $claimed = DB::table('service_requests')->where('id', $id)
            ->where('mail_status', 'pending')->where('mail_retry_at', '<=', now())
            ->update(['mail_status' => 'sending', 'mail_locked_at' => now(), 'mail_attempts' => DB::raw('mail_attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $row = DB::table('service_requests')->where('id', $id)->first();
        try {
            if (! app()->environment('testing') && in_array(config('mail.default'), ['log', 'array', 'failover'], true)) {
                throw new \RuntimeException('A delivery mail transport is required');
            }
            $details = json_decode($row->details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            $title = $details['form_title'] ?? config('forms.titles.'.$row->form_id)
                ?? config('forms.types')[$row->service_type] ?? 'Новое обращение';
            $title = mb_substr(trim(preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 160);
            if ($title === '') {
                $title = 'Новое обращение';
            }
            $labels = ['company' => 'Компания', 'partnership_status' => 'Формат сотрудничества', 'contract_number' => 'Номер договора', 'position' => 'Вакансия', 'partner_type' => 'Тип партнёра', 'inn' => 'ИНН', 'website' => 'Сайт'];
            $values = ['referral' => 'Вы приводите клиентов', 'supplier' => 'Фабрика или поставщик', 'manufacturer' => 'Производитель', 'designer' => 'Дизайнер', 'assembler' => 'Сборщик'];
            $displayDetails = [];
            foreach ($labels as $key => $label) {
                if (! empty($details[$key])) {
                    $displayDetails[$label] = $values[$details[$key]] ?? $details[$key];
                }
            }
            $data = [
                'form_title' => $title, 'display_details' => $displayDetails,
                'submitted_label' => Carbon::parse($row->created_at, config('app.timezone'))->setTimezone('Europe/Moscow')->locale('ru')->translatedFormat('j F Y, H:i'),
                'request_id' => $id, 'form_id' => $row->form_id,
                'client_name' => $row->name, 'client_email' => $row->email,
                'phone' => $row->phone ?: null, 'company' => $details['company'] ?? null,
                'partnership_status' => $row->service_type === 'partnership' ? (($details['partnership_status'] ?? '') === 'supplier' ? 'Вы фабрика или поставщик' : 'Вы приводите клиентов') : null,
                'contract_number' => $details['contract_number'] ?? null,
                'service_type_label' => config('forms.types')[$row->service_type] ?? $row->service_type,
                'client_message' => $row->message, 'source_url' => $row->source_url,
                'city' => $row->city, 'details' => $details,
                'submitted_at' => $row->created_at,
            ];
            $attachments = DB::table('service_request_attachments')->where('service_request_id', $id)->get();
            Mail::send('emails.form-submission', $data, function ($message) use ($row, $data, $attachments) {
                $message->to($row->recipient_email)->subject('LEGET — '.$data['form_title']);
                if ($row->email) {
                    $message->replyTo($row->email);
                }
                foreach ($attachments as $file) {
                    $message->attachData(Crypt::decryptString(Storage::disk($file->disk)->get($file->path)), $file->name, ['mime' => $file->mime]);
                }
            });
            DB::table('service_requests')->where('id', $id)->update([
                'mail_status' => 'sent', 'mail_sent_at' => now(), 'mail_locked_at' => null,
                'mail_retry_at' => null, 'mail_error' => null, 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('service_requests')->where('id', $id)->update([
                'mail_status' => $row->mail_attempts >= config('forms.max_attempts') ? 'failed' : 'pending',
                'mail_retry_at' => now()->addMinutes(min(60, 2 ** min($row->mail_attempts, 6))),
                'mail_locked_at' => null, 'mail_error' => class_basename($e), 'updated_at' => now(),
            ]);
            Log::warning('Form mail delivery deferred', ['request_id' => $id, 'error_type' => class_basename($e)]);
        }
    }
}
