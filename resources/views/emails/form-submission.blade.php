<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $form_title }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .outer { padding: 20px 12px !important; }
            .content { padding: 28px 22px !important; }
            .heading { font-size: 27px !important; line-height: 34px !important; }
            .field-label { width: 105px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;color:#20242b;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;font-size:1px;color:#f3f4f6;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{ $form_title }}@if($client_name) — {{ $client_name }}@endif. Новое обращение с сайта.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
<tr><td class="outer" align="center" style="padding:40px 20px;">
    <!--[if mso]><table role="presentation" width="600"><tr><td><![endif]-->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
        <tr><td style="padding:0 4px 20px;font-size:22px;line-height:28px;font-weight:bold;letter-spacing:2px;color:#252b34;">LEGET</td></tr>
        <tr><td style="background-color:#ffffff;border:1px solid #e1e5ea;border-radius:16px;overflow:hidden;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td class="content" style="padding:36px 36px 30px;border-bottom:1px solid #edf0f3;">
                    <p style="margin:0 0 14px;font-size:12px;line-height:18px;font-weight:bold;letter-spacing:1.5px;text-transform:uppercase;color:#527664;">Новое обращение</p>
                    <h1 class="heading" style="margin:0 0 14px;font-size:32px;line-height:40px;letter-spacing:-0.6px;font-weight:bold;color:#20242b;">{{ $form_title }}</h1>
                    <p style="margin:0;font-size:13px;line-height:21px;color:#7b828e;">{{ $submitted_label }} · МСК</p>
                </td></tr>
                <tr><td class="content" style="padding:30px 36px 34px;">
                    <p style="margin:0 0 18px;font-size:12px;line-height:18px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#7b828e;">Контактные данные</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;font-size:15px;line-height:24px;">
                    @foreach(['Имя' => $client_name, 'Телефон' => $phone, 'Почта' => $client_email, 'Город' => $city] as $label => $value)
                        @if($value)
                        <tr>
                            <th class="field-label" align="left" valign="top" width="125" style="width:125px;padding:0 12px 12px 0;font-weight:normal;color:#7b828e;">{{ $label }}</th>
                            <td valign="top" style="padding:0 0 12px;color:#20242b;word-wrap:break-word;overflow-wrap:anywhere;">
                                @if($label === 'Почта')<a href="mailto:{{ $value }}" style="color:#305f50;text-decoration:underline;">{{ $value }}</a>
                                @elseif($label === 'Телефон')<a href="tel:{{ preg_replace('/[^+0-9]/', '', $value) }}" style="color:#20242b;text-decoration:none;font-weight:bold;">{{ $value }}</a>
                                @else{{ $value }}@endif
                            </td>
                        </tr>
                        @endif
                    @endforeach
                    </table>
                    @if(count($display_details))
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;margin-top:12px;border-top:1px solid #edf0f3;font-size:14px;line-height:23px;">
                        @foreach($display_details as $label => $value)
                        <tr><th class="field-label" align="left" valign="top" width="125" style="width:125px;padding:14px 12px 0 0;font-weight:normal;color:#7b828e;">{{ $label }}</th><td style="padding:14px 0 0;word-wrap:break-word;overflow-wrap:anywhere;">{{ $value }}</td></tr>
                        @endforeach
                    </table>
                    @endif
                    @if($client_message)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;background-color:#f6f7f8;border-radius:10px;">
                        <tr><td style="padding:20px 22px;">
                            <p style="margin:0 0 9px;font-size:12px;line-height:18px;font-weight:bold;color:#7b828e;">СООБЩЕНИЕ</p>
                            <p style="margin:0;font-size:15px;line-height:25px;color:#333a44;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere;">{{ $client_message }}</p>
                        </td></tr>
                    </table>
                    @endif
                    @if($client_email)
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;"><tr><td bgcolor="#263c33" style="border-radius:8px;background-color:#263c33;">
                        <a href="mailto:{{ $client_email }}" style="display:inline-block;padding:14px 24px;border:1px solid #263c33;border-radius:8px;color:#ffffff;font-size:14px;line-height:20px;font-weight:bold;text-decoration:none;">Ответить на письмо</a>
                    </td></tr></table>
                    @endif
                </td></tr>
                @if($source_url && in_array(parse_url($source_url, PHP_URL_SCHEME), ['https', 'http'], true))
                <tr><td class="content" style="padding:20px 36px;border-top:1px solid #edf0f3;font-size:12px;line-height:20px;color:#7b828e;">
                    Отправлено с сайта<br>
                    <a href="{{ $source_url }}" style="color:#566171;text-decoration:underline;word-break:break-word;">{{ parse_url($source_url, PHP_URL_HOST) }}</a>
                </td></tr>
                @endif
            </table>
        </td></tr>
        <tr><td style="padding:22px 4px 0;font-size:12px;line-height:20px;color:#8a919b;">Уведомление о заявке · LEGET
@if($client_email)<br>Ответ на это письмо будет адресован отправителю формы.@endif</td></tr>
    </table>
    <!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
