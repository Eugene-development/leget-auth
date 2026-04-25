<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Подтверждение Email — LEGET</title>
    <style type="text/css">
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .content-card { margin: 10px !important; border-radius: 12px !important; }
            .header-padding { padding: 30px 20px !important; }
            .content-padding { padding: 30px 20px !important; }
            .title-text { font-size: 28px !important; letter-spacing: 4px !important; }
            .welcome-text { font-size: 22px !important; }
            .button-padding { padding: 14px 24px !important; font-size: 14px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #f1f5f9;">
    <!-- Email Container -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="min-height: 100vh; background-color: #0f172a;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <!-- Main Content Card -->
                <table width="600" cellpadding="0" cellspacing="0" border="0" class="content-card"
                       style="max-width: 600px; width: 100%; background: #1e293b; border-radius: 16px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08);">

                    <!-- Header -->
                    <tr>
                        <td style="padding: 0;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="header-padding"
                                        style="background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #3b82f6 100%); padding: 40px 40px; text-align: center;">
                                        <!-- Logo -->
                                        <h1 class="title-text"
                                            style="margin: 0; font-size: 40px; font-weight: 700; letter-spacing: 10px; color: #ffffff; text-transform: uppercase; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);">
                                            LEGET
                                        </h1>
                                        <p style="margin: 8px 0 0 0; font-size: 13px; color: rgba(255,255,255,0.7); letter-spacing: 3px; text-transform: uppercase;">
                                            Создаём сайты под ключ
                                        </p>
                                        <div style="margin-top: 16px; height: 2px; width: 60px; background: rgba(255,255,255,0.4); margin-left: auto; margin-right: auto; border-radius: 2px;"></div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Main Content -->
                    <tr>
                        <td class="content-padding" style="padding: 50px 40px;">
                            <!-- Welcome Message -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="text-align: center; padding-bottom: 32px;">
                                        <!-- Check icon -->
                                        <div style="display: inline-block; width: 64px; height: 64px; background: linear-gradient(135deg, #1d4ed8, #3b82f6); border-radius: 50%; margin-bottom: 20px;">
                                            <table cellpadding="0" cellspacing="0" border="0" width="64" height="64">
                                                <tr>
                                                    <td align="center" valign="middle" style="text-align:center; vertical-align:middle;">
                                                        <span style="color: #ffffff; font-size: 28px; line-height: 1;">✉</span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                        <h2 class="welcome-text"
                                            style="margin: 0; font-size: 26px; font-weight: 600; color: #f1f5f9; letter-spacing: 1px;">
                                            Добро пожаловать, {{ $user->name }}!
                                        </h2>
                                        <p style="margin: 12px 0 0 0; font-size: 15px; color: #94a3b8; line-height: 1.7;">
                                            Спасибо за регистрацию в сервисе LEGET
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Message Content -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background: rgba(255,255,255,0.04); border-radius: 12px; padding: 32px; border: 1px solid rgba(255,255,255,0.08);">
                                        <p style="margin: 0 0 20px 0; font-size: 16px; color: #cbd5e1; line-height: 1.7; text-align: center;">
                                            Для завершения регистрации и получения доступа к личному кабинету необходимо подтвердить ваш адрес электронной почты.
                                        </p>
                                        <p style="margin: 0 0 32px 0; font-size: 15px; color: #94a3b8; line-height: 1.7; text-align: center;">
                                            Нажмите на кнопку ниже, чтобы подтвердить email:
                                        </p>

                                        <!-- Verification Button -->
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 32px 0;">
                                            <tr>
                                                <td align="center">
                                                    <table cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td style="background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%); border-radius: 8px; box-shadow: 0 4px 20px rgba(29, 78, 216, 0.5);">
                                                                <a href="{{ $verificationUrl }}"
                                                                   class="button-padding"
                                                                   style="display: inline-block; padding: 16px 40px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none; letter-spacing: 1px; text-transform: uppercase; border-radius: 8px;"
                                                                   target="_blank">
                                                                    Подтвердить Email
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Note about link expiry -->
                                        <p style="margin: 24px 0 0 0; font-size: 13px; color: #64748b; line-height: 1.6; text-align: center;">
                                            Если кнопка не работает, скопируйте эту ссылку в браузер:
                                        </p>
                                        <p style="margin: 8px 0 0 0; word-break: break-all; text-align: center;">
                                            <a href="{{ $verificationUrl }}"
                                               style="color: #60a5fa; text-decoration: underline; font-size: 13px;"
                                               target="_blank">{{ $verificationUrl }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background: rgba(255,255,255,0.03); padding: 28px 40px; border-top: 1px solid rgba(255,255,255,0.06);">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 0 0 12px 0; font-size: 13px; color: #64748b; line-height: 1.6;">
                                            Если вы не регистрировались на нашем сайте — просто проигнорируйте это письмо.
                                        </p>
                                        <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                                            С уважением,<br>
                                            <strong style="color: #94a3b8; letter-spacing: 2px;">Команда LEGET</strong>
                                        </p>
                                        <p style="margin: 16px 0 0 0; font-size: 12px; color: #334155;">
                                            © {{ date('Y') }} LEGET. Все права защищены.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
