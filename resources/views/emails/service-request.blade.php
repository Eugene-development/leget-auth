<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новая заявка на услугу — LEGET</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: #0f172a;
            color: white;
            padding: 30px;
            text-align: center;
            border-bottom: 3px solid #3b82f6;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .header .brand {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.3em;
            color: #f1f5f9;
            margin-bottom: 8px;
        }
        .header p {
            margin: 8px 0 0;
            opacity: 0.7;
            font-size: 13px;
        }
        .content {
            padding: 30px;
        }
        .info-block {
            background: #f8fafc;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        .info-row {
            display: flex;
            margin-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
        }
        .info-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: #64748b;
            width: 150px;
            flex-shrink: 0;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .info-value {
            color: #0f172a;
            word-break: break-word;
        }
        .message-block {
            background: #f1f5f9;
            border-left: 3px solid #3b82f6;
            padding: 15px;
            margin-top: 20px;
            border-radius: 0 4px 4px 0;
        }
        .message-block h3 {
            margin: 0 0 10px;
            color: #475569;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .message-block p {
            margin: 0;
            color: #334155;
            white-space: pre-wrap;
        }
        .footer {
            background: #0f172a;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }
        .footer a {
            color: #cbd5e1;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="brand">LEGET</div>
            <h1>🛠️ Заявка: {{ $service_type_label }}</h1>
            <p>{{ $submitted_at }}</p>
        </div>

        <div class="content">
            <div class="info-block">
                <div class="info-row">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">{{ $client_name }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Телефон:</span>
                    <span class="info-value">
                        @if($phone)
                        <a href="tel:{{ $phone }}" style="color: #2563eb; text-decoration: none; font-weight: 600;">{{ $phone }}</a>
                        @else Не указан @endif
                    </span>
                </div>
                @if(!empty($client_email))
                <div class="info-row"><span class="info-label">Почта:</span><span class="info-value">{{ $client_email }}</span></div>
                @endif
                @if(!empty($company))
                <div class="info-row"><span class="info-label">Компания:</span><span class="info-value">{{ $company }}</span></div>
                @endif
                @if(!empty($partnership_status))
                <div class="info-row"><span class="info-label">Статус:</span><span class="info-value">{{ $partnership_status }}</span></div>
                @endif
				@if(!empty($contract_number))
				<div class="info-row"><span class="info-label">Номер договора:</span><span class="info-value">{{ $contract_number }}</span></div>
				@endif
                <div class="info-row">
                    <span class="info-label">Тип услуги:</span>
                    <span class="info-value" style="font-weight: 600;">{{ $service_type_label }}</span>
                </div>
                @if($city)
                <div class="info-row">
                    <span class="info-label">Город:</span>
                    <span class="info-value">{{ $city }}</span>
                </div>
                @endif
                @if($source_url)
                <div class="info-row">
                    <span class="info-label">Страница:</span>
                    <span class="info-value" style="font-size: 12px; color: #64748b;">
                        <a href="{{ $source_url }}" style="color: #64748b; text-decoration: underline;" target="_blank">{{ $source_url }}</a>
                    </span>
                </div>
                @endif
            </div>

            @if($client_message)
            <div class="message-block">
                <h3>💬 Сообщение / Комментарий:</h3>
                <p>{!! nl2br(e($client_message)) !!}</p>
            </div>
            @endif
        </div>

        <div class="footer">
            <p>Автоматическое уведомление от платформы <a href="https://leget.ru">LEGET</a></p>
            <p>Пожалуйста, свяжитесь с клиентом в ближайшее время</p>
        </div>
    </div>
</body>
</html>
