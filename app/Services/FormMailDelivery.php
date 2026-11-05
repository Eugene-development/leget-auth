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
    public function __construct(private FormMailOutbox $outbox) {}

    /** Operator/CLI entry point. HTTP controllers only persist the outbox. */
    public function deliver(string $id): void
    {
        while ($this->processOne($id)) {
            // Each iteration claims a single recipient; failed recipients wait for their retry time.
        }
    }

    public function processOne(?string $requestId = null): bool
    {
        $delivery = $this->outbox->claim($requestId);
        if (! $delivery) {
            return false;
        }
        if (isset($delivery->recovered)) {
            return true;
        }
        try {
            if (! app()->environment('testing') && in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array', 'failover', 'roundrobin'], true)) {
                throw new \RuntimeException('A delivery mail transport is required');
            }
            $recipient = $this->outbox->recipient($delivery);
            if ($recipient === null) {
                $this->outbox->finish($delivery, 'skipped', 'RecipientAccessRevoked');

                return true;
            }
            $row = DB::table('service_requests')->where('id', $delivery->service_request_id)->first();
            $this->send($row, $recipient);
        } catch (\Throwable $e) {
            $this->outbox->finish($delivery, $delivery->attempts >= config('forms.max_attempts') ? 'failed' : 'pending', $e->getMessage() === 'FormRecipientInvalid' ? 'FormRecipientInvalid' : class_basename($e));
            Log::warning('Form mail delivery deferred', ['request_id' => $delivery->service_request_id, 'delivery_id' => $delivery->id, 'error_type' => class_basename($e)]);

            return true;
        }
        // A failed database write after SMTP must surface; never treat it as an SMTP rejection.
        $this->outbox->finish($delivery, 'sent');

        return true;
    }

    private function send(object $row, string $recipient): void
    {
        $id = $row->id;
        $details = json_decode($row->details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        $title = $details['form_title'] ?? config('forms.titles.'.$row->form_id)
            ?? config('forms.types')[$row->service_type] ?? 'Новое обращение';
        $title = mb_substr(trim(preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 160);
        if ($title === '') {
            $title = 'Новое обращение';
        }
        $labels = ['company' => 'Компания', 'partnership_status' => 'Статус отправителя', 'contract_number' => 'Номер договора', 'position' => 'Вакансия', 'partner_type' => 'Тип партнёра', 'inn' => 'ИНН', 'website' => 'Сайт', 'object_address' => 'Адрес объекта', 'visit_time' => 'Удобное время встречи'];
        $values = ['referral' => 'Вы приводите клиентов', 'supplier' => 'Фабрика или поставщик', 'manufacturer' => 'Производитель', 'designer' => 'Дизайнер', 'assembler' => 'Сборщик'];
        $partnershipLabels = ['referral' => 'Вы приводите клиентов', 'manufacturer' => 'Вы фабрика изготовитель', 'supplier' => 'Вы поставщик', 'other' => 'Иное'];
        $displayDetails = [];
        if (! empty($details['consent'])) {
            $displayDetails['Согласие на обработку персональных данных'] = 'Получено';
        }
        foreach ($labels as $key => $label) {
            if (! empty($details[$key])) {
                $displayDetails[$label] = ($key === 'partnership_status' ? $partnershipLabels[$details[$key]] ?? null : $values[$details[$key]] ?? null) ?? $details[$key];
            }
        }
        if (! empty($details['promo'])) {
            $displayDetails['Персональный промокод'] = $details['promo']['code'];
            $displayDetails['Скидка на ВСЁ'] = $details['promo']['discount'];
            $displayDetails['Условия промокода'] = $details['promo']['terms'];
            $displayDetails['Действие для куратора'] = 'Отправьте этот промокод клиенту на указанный телефон.';
        }
        if (! empty($details['dimensions']) && is_array($details['dimensions'])) {
            $displayDetails['Размеры'] = implode("\n", array_map(
                fn ($value, $index) => ($index + 1).'. '.$value,
                $details['dimensions'],
                array_keys($details['dimensions'])
            ));
        }
        if (! empty($details['selection'])) {
            $selection = $details['selection'];
            $displayDetails['Подборка'] = $selection['title'];
            $displayDetails['Количество проектов и материалов'] = (string) count($selection['items']);
        }
        if (! empty($details['estimate'])) {
            $estimate = $details['estimate'];
            $input = $estimate['inputs'];
            $layouts = ['straight' => 'Прямая', 'l' => 'Угловая', 'u' => 'П-образная', 'island' => 'С островом'];
            $displayDetails['Параметры кухни'] = 'Общая длина: '.$input['run_cm'].' см; планировка: '.$layouts[$input['layout']].'; материал: '.$estimate['material_label'].'; единиц техники: '.$input['equipment'];
            $displayDetails['Предварительный диапазон'] = number_format($estimate['min'], 0, ',', ' ').'–'.number_format($estimate['max'], 0, ',', ' ').' ₽';
            if (! empty($estimate['note'])) {
                $displayDetails['Условия расчёта'] = $estimate['note'];
            }
        }
        if (! empty($details['order_title'])) {
            $displayDetails['Заказ, указанный клиентом'] = $details['order_title'];
        }
        $data = [
            'form_title' => $title, 'display_details' => $displayDetails,
            'submitted_label' => Carbon::parse($row->created_at, config('app.timezone'))->setTimezone('Europe/Moscow')->locale('ru')->translatedFormat('j F Y, H:i'),
            'request_id' => $id, 'form_id' => $row->form_id,
            'client_name' => $row->name, 'client_email' => $row->email,
            'phone' => $row->phone ?: null, 'company' => $details['company'] ?? null,
            'partnership_status' => $row->service_type === 'partnership' ? ($partnershipLabels[$details['partnership_status'] ?? ''] ?? null) : null,
            'contract_number' => $details['contract_number'] ?? null,
            'service_type_label' => config('forms.types')[$row->service_type] ?? $row->service_type,
            'client_message' => $row->message, 'source_url' => $row->source_url,
            'city' => $row->city, 'details' => $details,
            'submitted_at' => $row->created_at,
        ];
        $attachments = DB::table('service_request_attachments')->where('service_request_id', $id)->get();
        Mail::send('emails.form-submission', $data, function ($message) use ($row, $data, $attachments, $recipient) {
            $message->to($recipient)->subject('LEGET — '.$data['form_title']);
            if ($row->email) {
                $message->replyTo($row->email);
            }
            foreach ($attachments as $file) {
                $message->attachData(Crypt::decryptString(Storage::disk($file->disk)->get($file->path)), $file->name, ['mime' => $file->mime]);
            }
        });
    }
}
