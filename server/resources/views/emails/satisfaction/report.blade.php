<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A sua nova viatura</title>
</head>
<body style="margin:0; padding:0; background:#f4f5f7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#101828;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7; padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#ffffff; border-radius:20px; overflow:hidden; box-shadow:0 10px 30px rgba(16,24,40,.08);">
                    <tr>
                        <td style="padding:30px 30px 8px; text-align:center;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $companyName }}" style="max-height:52px; max-width:180px;">
                            @else
                                <div style="font-size:19px; font-weight:700;">{{ $companyName }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 30px 4px; text-align:center;">
                            <div style="display:inline-block; padding:6px 14px; border-radius:999px; background:#e6f8f0; color:#059669; font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;">
                                Parabéns pela compra
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 34px 4px;">
                            <p style="margin:0; font-size:16px; line-height:1.6; color:#475467; text-align:center;">
                                {{ $message }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px 34px 34px; text-align:center;">
                            <a href="{{ $link }}" target="_blank"
                               style="display:inline-block; padding:14px 30px; border-radius:14px; background:#10b981; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none;">
                                Ver a minha viatura
                            </a>
                        </td>
                    </tr>
                </table>
                <p style="margin:18px 0 0; font-size:12px; color:#98a2b3;">
                    ✦ feito com <strong style="color:#667085;">XPLENDOR</strong>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
