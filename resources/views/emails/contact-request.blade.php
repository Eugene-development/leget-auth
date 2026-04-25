<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новая заявка — LEGET</title>
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
            background: #171717;
            color: white;
            padding: 30px;
            text-align: center;
            border-bottom: 3px solid #737373;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 400;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .header .brand {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.3em;
            color: #d4d4d4;
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
            background: #f9f9f9;
            border-radius: 2px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #ebebeb;
        }
        .info-row {
            display: flex;
            margin-bottom: 12px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 12px;
        }
        .info-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: #6b7280;
            width: 150px;
            flex-shrink: 0;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .info-value {
            color: #111827;
            word-break: break-word;
        }
        .message-block {
            background: #f5f5f5;
            border-left: 3px solid #737373;
            padding: 15px;
            margin-top: 20px;
        }
        .message-block h3 {
            margin: 0 0 10px;
            color: #404040;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .message-block p {
            margin: 0;
            color: #525252;
        }
        .footer {
            background: #171717;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
        }
        .footer a {
            color: #d4d4d4;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="brand">LEGET</div>
            <h1>🔔 Новая заявка с сайта</h1>
            <p>{{ $submitted_at }}</p>
        </div>

        <div class="content">
            <div class="info-block">
                <div class="info-row">
                    <span class="info-label">Имя:</span>
                    <span class="info-value">{{ $client_name }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">
                        <a href="mailto:{{ $client_email }}" style="color: #525252; text-decoration: none; font-weight: 600;">{{ $client_email }}</a>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Телефон:</span>
                    <span class="info-value">
                        @if($phone !== 'Не указан')
                            <a href="tel:{{ $phone }}" style="color: #525252; text-decoration: none; font-weight: 600;">{{ $phone }}</a>
                        @else
                            <span style="color: #9ca3af;">{{ $phone }}</span>
                        @endif
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Компания:</span>
                    <span class="info-value">{{ $company }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Страница:</span>
                    <span class="info-value" style="font-size: 12px; color: #6b7280;">{{ $source_url }}</span>
                </div>
            </div>

            <div class="message-block">
                <h3>💬 Сообщение:</h3>
                <p>{!! nl2br(e($client_message)) !!}</p>
            </div>
        </div>

        <div class="footer">
            <p>Автоматическое уведомление от сайта <a href="https://leget.ru">LEGET</a></p>
            <p>Пожалуйста, свяжитесь с клиентом как можно скорее</p>
        </div>
    </div>
</body>
</html>
