<!doctype html>
<html lang="ru"><meta charset="utf-8"><body>
<h1>{{ $service_type_label }}</h1>
<p>Заявка: {{ $request_id }}<br>Форма: {{ $form_id }}<br>Дата: {{ $submitted_at }}</p>
<table cellpadding="6">
@foreach(['Имя' => $client_name, 'Телефон' => $phone, 'Email' => $client_email, 'Город' => $city, 'Страница' => $source_url] as $label => $value)
@if($value)<tr><th align="left">{{ $label }}</th><td>{{ $value }}</td></tr>@endif
@endforeach
@foreach($details as $label => $value)
@if($value)<tr><th align="left">{{ $label }}</th><td>{{ $value }}</td></tr>@endif
@endforeach
</table>
@if($client_message)<p style="white-space: pre-wrap">{{ $client_message }}</p>@endif
</body></html>
