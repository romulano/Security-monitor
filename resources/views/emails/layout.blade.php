<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title ?? 'Security Monitor' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2430;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;border:1px solid #e3e6eb;">
                    <tr>
                        <td style="padding:24px 28px 8px;font-size:15px;font-weight:600;letter-spacing:.02em;color:#0e1117;">Security Monitor</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 28px;font-size:15px;line-height:1.6;">
                            @yield('body')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px;border-top:1px solid #e3e6eb;font-size:12px;line-height:1.5;color:#6b7280;">
                            Security Monitor · inteligência de ameaças · <a href="{{ config('app.url') }}" style="color:#6b7280;">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</a><br>
                            Dúvidas: responda este e-mail ou escreva para {{ config('secmon.contact_email') }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
