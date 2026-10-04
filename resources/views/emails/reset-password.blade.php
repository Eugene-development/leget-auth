<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Восстановление пароля — LEGET</title></head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,sans-serif;color:#172033;line-height:1.6">
<table role="presentation" style="max-width:600px;width:100%;margin:auto;background:#ffffff;border-radius:12px" cellpadding="0" cellspacing="0">
    <tr><td style="padding:32px">
        <p style="margin-top:0;font-weight:bold;letter-spacing:4px">LEGET</p>
        <h1 style="font-size:26px;line-height:1.3">Восстановление пароля</h1>
        <p>Мы получили запрос на восстановление пароля вашего аккаунта.</p>
        <p>Чтобы задать новый пароль, нажмите на кнопку. Ссылка действует {{ $expiresIn }} минут и используется один раз.</p>
        <p style="margin:28px 0"><a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 24px;background:#1d4ed8;color:#ffffff;border-radius:8px;text-decoration:none">Задать новый пароль</a></p>
        <p>Если кнопка не работает, скопируйте ссылку в браузер:</p>
        <p style="word-break:break-all"><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
        <p>Если вы не запрашивали восстановление, просто проигнорируйте это письмо. Ваш пароль останется прежним.</p>
        <p>С уважением,<br>Команда LEGET</p>
    </td></tr>
</table>
</body>
</html>
