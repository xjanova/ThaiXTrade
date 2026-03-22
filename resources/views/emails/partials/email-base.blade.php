@php
    $siteLogo = \App\Models\SiteSetting::get('general', 'logo');
    $appName = config('app.name', 'TPIX TRADE');
    $appUrl = config('app.url');
    $fromEmail = config('mail.from.address', 'tpixtrade@xman4289.com');
    $primaryColor = '#06b6d4';
    $primaryDark = '#0891b2';
@endphp
<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', $appName)</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Noto Sans Thai', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.7;
            color: #f1f5f9;
            background-color: #000000;
            -webkit-font-smoothing: antialiased;
        }
        .email-wrapper {
            max-width: 640px;
            margin: 0 auto;
            background: #000000;
        }
        .email-header {
            background: linear-gradient(135deg, {{ $primaryColor }} 0%, #8b5cf6 50%, #a855f7 100%);
            padding: 40px 40px 32px;
            text-align: center;
        }
        .email-logo {
            margin-bottom: 20px;
        }
        .email-logo img {
            max-height: 80px;
            max-width: 280px;
        }
        .email-logo-text {
            font-size: 28px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 2px;
        }
        .email-header-badge {
            display: inline-block;
            padding: 6px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 12px;
        }
        .badge-order {
            background: rgba(255,255,255,0.2);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .badge-success {
            background: #00C853;
            color: #ffffff;
        }
        .badge-test {
            background: #f59e0b;
            color: #ffffff;
        }
        .badge-alert {
            background: #FF1744;
            color: #ffffff;
        }
        .email-header h1 {
            color: #ffffff;
            font-size: 24px;
            font-weight: 700;
            margin: 12px 0 4px;
        }
        .email-header p {
            color: rgba(255,255,255,0.9);
            font-size: 15px;
        }
        .email-body {
            padding: 32px 40px;
            background: #000000;
        }
        .greeting {
            font-size: 17px;
            color: #e2e8f0;
            margin-bottom: 16px;
        }
        .greeting strong {
            color: {{ $primaryColor }};
        }
        .card {
            background: #111111;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 24px;
            margin: 20px 0;
        }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #94a3b8; }
        .info-value { color: #f1f5f9; font-weight: 500; }
        .order-table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
            font-size: 14px;
        }
        .order-table th {
            background: #111111;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: #e2e8f0;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .order-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            color: #cbd5e1;
        }
        .order-table .total-row td {
            font-weight: 700;
            color: #f1f5f9;
            font-size: 16px;
            background: #111111;
            border-top: 2px solid {{ $primaryColor }};
        }
        .warning-box {
            background: rgba(255,214,0,0.1);
            border: 1px solid #fbbf24;
            border-left: 4px solid #f59e0b;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 20px 0;
            font-size: 14px;
            color: #fde68a;
        }
        .success-box {
            background: rgba(0,200,83,0.1);
            border: 1px solid #00C853;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
        }
        .btn-primary {
            background: {{ $primaryColor }};
            color: #ffffff !important;
        }
        .btn-success {
            background: #00C853;
            color: #ffffff !important;
        }
        .btn-danger {
            background: #FF1744;
            color: #ffffff !important;
        }
        .btn-block {
            display: block;
            width: 100%;
        }
        .text-center { text-align: center; }
        .text-green { color: #00C853; }
        .text-red { color: #FF1744; }
        .text-cyan { color: {{ $primaryColor }}; }
        .mt-4 { margin-top: 16px; }
        .mt-6 { margin-top: 24px; }
        .mb-4 { margin-bottom: 16px; }

        .email-footer {
            background: #000000;
            padding: 32px 40px;
            text-align: center;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .footer-logo {
            margin-bottom: 16px;
        }
        .footer-logo img {
            max-height: 40px;
        }
        .footer-text {
            color: #94a3b8;
            font-size: 13px;
            line-height: 1.8;
        }
        .footer-text a {
            color: {{ $primaryColor }};
            text-decoration: none;
        }
        .footer-divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin: 20px 0;
        }
        .footer-copyright {
            color: #64748b;
            font-size: 12px;
        }

        @media only screen and (max-width: 640px) {
            .email-wrapper { width: 100% !important; }
            .email-header, .email-body, .email-footer { padding: 24px 20px !important; }
            .card { padding: 16px !important; }
            .email-logo img { max-height: 64px !important; max-width: 220px !important; }
        }
    </style>
</head>
<body style="background-color: #000000; margin: 0; padding: 20px 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #000000;">
        <tr>
            <td align="center">
                <div class="email-wrapper" style="max-width: 640px; background: #000000; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.5);">

                    {{-- HEADER --}}
                    <div class="email-header">
                        <div class="email-logo">
                            @if($siteLogo)
                                <img src="{{ $appUrl }}/storage/{{ $siteLogo }}" alt="{{ $appName }}" style="max-height: 80px; max-width: 280px;">
                            @else
                                <div class="email-logo-text">{{ $appName }}</div>
                            @endif
                        </div>
                        @yield('header')
                    </div>

                    {{-- BODY --}}
                    <div class="email-body">
                        @yield('body')
                    </div>

                    {{-- FOOTER --}}
                    <div class="email-footer">
                        <div class="footer-logo">
                            @if($siteLogo)
                                <img src="{{ $appUrl }}/storage/{{ $siteLogo }}" alt="{{ $appName }}" style="max-height: 40px;">
                            @else
                                <div style="color: #ffffff; font-size: 18px; font-weight: 700; letter-spacing: 2px;">{{ $appName }}</div>
                            @endif
                        </div>
                        <div class="footer-text">
                            <a href="{{ $appUrl }}">{{ $appUrl }}</a>
                        </div>
                        <hr class="footer-divider">
                        <p class="footer-copyright">
                            &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.<br>
                            Developed by <a href="https://xmanstudio.com" style="color: {{ $primaryColor }};">Xman Studio</a><br>
                            อีเมลนี้ถูกส่งโดยอัตโนมัติ กรุณาอย่าตอบกลับ
                        </p>
                    </div>

                </div>
            </td>
        </tr>
    </table>
</body>
</html>
