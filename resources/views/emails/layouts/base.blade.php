<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title ?? 'Trakli' }}</title>
    <!--[if !mso]><!-->
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
    <!--<![endif]-->
    <style type="text/css">
        body { margin: 0 !important; padding: 0 !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; display: block; }
        a { color: #047844; text-decoration: none; }
        a:hover { text-decoration: underline; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; }
            .px-card { padding-left: 22px !important; padding-right: 22px !important; }
            .py-card { padding-top: 26px !important; padding-bottom: 26px !important; }
            .h1 { font-size: 22px !important; line-height: 1.25 !important; }
            .stack > tbody > tr > td { display: block !important; width: 100% !important; padding: 4px 0 !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .bg-page { background-color: #0f1411 !important; }
            .bg-card { background-color: #181d1a !important; border-color: #262d28 !important; }
            .text-default { color: #e7eae8 !important; }
            .text-muted { color: #99a39e !important; }
            .text-heading { color: #e7eae8 !important; }
            .border-soft { border-color: #262d28 !important; }
            .panel { background-color: #1c2521 !important; border-color: #262d28 !important; }
            .row-divider { border-color: #262d28 !important; }
        }
    </style>
</head>
<body class="bg-page text-default" style="margin:0; padding:0; background-color:#f6f8f7; color:#1f2937; font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f6f8f7;">
    {{ $preheader ?? '' }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-page" bgcolor="#f6f8f7" style="background-color:#f6f8f7; font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <tr>
        <td align="center" style="padding: 32px 16px;">
            <table role="presentation" class="container bg-card" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#ffffff; border:1px solid #e5e9e7; border-radius:6px;">

                <tr>
                    <td class="px-card" align="center" style="padding: 24px 32px; border-bottom: 1px solid #e5e9e7; text-align: center;">
                        <a href="{{ config('app.frontend_url', config('app.url')) }}" style="text-decoration:none; display:inline-block; line-height:0;">
                            @include('emails.partials.logo')
                        </a>
                    </td>
                </tr>

                <tr>
                    <td class="px-card py-card text-default" style="padding: 32px; color:#1f2937; font-size:15px; line-height:1.6; font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        @yield('content')
                    </td>
                </tr>

                <tr>
                    <td class="px-card text-muted border-soft" style="padding: 22px 32px 26px; border-top: 1px solid #e5e9e7; color:#6b7280; font-size:12px; line-height:1.7; text-align:center; font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        @hasSection('footerNote')
                            <div style="margin-bottom:10px;">@yield('footerNote')</div>
                        @endif
                        <div>
                            <a href="{{ config('app.frontend_url', config('app.url')) }}/settings" style="color:#047844; text-decoration:none;">Manage preferences</a>
                            &nbsp;&middot;&nbsp;
                            <a href="https://trakli.app/blog/" style="color:#047844; text-decoration:none;">Blog</a>
                            &nbsp;&middot;&nbsp;
                            <a href="mailto:support@trakli.app" style="color:#047844; text-decoration:none;">Support</a>
                        </div>
                        <div style="margin-top:8px; color:#9ca3af;">&copy; {{ date('Y') }} Trakli</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
