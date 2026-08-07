<!doctype html>
<html lang="nb">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:Arial,Helvetica,sans-serif;">
    <div role="article" aria-roledescription="email" aria-label="{{ $title }}" lang="nb">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;padding:32px 12px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;">
                        <tr>
                            <td style="padding:28px 32px 16px;font-size:24px;font-weight:700;letter-spacing:-0.5px;">Uncovr</td>
                        </tr>
                        <tr>
                            <td style="padding:8px 32px 32px;line-height:1.6;font-size:16px;">
                                {{ $slot }}
                            </td>
                        </tr>
                    </table>
                    <p style="max-width:600px;margin:16px auto 0;color:#71717a;font-size:12px;line-height:1.5;">
                        Dette er en automatisk sikkerhetsmelding fra Uncovr.
                    </p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
