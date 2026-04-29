<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Trakli mail previews</title>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f6f8f7;
            color: #1f2937;
            margin: 0;
            padding: 40px 16px;
            line-height: 1.6;
        }
        .container {
            max-width: 760px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e5e9e7;
            border-radius: 6px;
        }
        .header {
            padding: 24px 32px;
            border-bottom: 1px solid #e5e9e7;
            text-align: center;
        }
        .header svg { display: inline-block; }
        .body { padding: 28px 32px; }
        h1 {
            margin: 0 0 6px;
            font-size: 22px;
            font-weight: 700;
            color: #0f3a23;
        }
        p.lede {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 14px;
        }
        .types {
            list-style: none;
            margin: 0 0 24px;
            padding: 0;
        }
        .types li {
            padding: 14px 16px;
            margin-bottom: 10px;
            background: #fafbfa;
            border: 1px solid #e5e9e7;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .types li:last-child { margin-bottom: 0; }
        .name { font-weight: 500; color: #1f2937; }
        .links { display: flex; gap: 8px; flex-wrap: wrap; }
        .links a {
            display: inline-block;
            padding: 6px 12px;
            font-size: 13px;
            color: #047844;
            background: #ffffff;
            border: 1px solid #e5e9e7;
            border-radius: 6px;
            text-decoration: none;
        }
        .links a.primary {
            color: #ffffff;
            background: #047844;
            border-color: #047844;
        }
        .help {
            background: #fafbfa;
            border: 1px solid #e5e9e7;
            border-radius: 6px;
            padding: 16px 18px;
            font-size: 13px;
            color: #374151;
        }
        .help-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f3a23;
            margin-bottom: 8px;
        }
        .help code {
            background: #eef2ee;
            padding: 1px 6px;
            border-radius: 4px;
            font-family: 'Ubuntu Mono', SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
        }
        .help-row { padding: 3px 0; }
        @media (max-width: 600px) {
            .body { padding: 22px; }
            .header { padding: 22px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            @include('emails.partials.logo')
        </div>
        <div class="body">
            <h1>Mail previews</h1>
            <p class="lede">Each Mailable rendered with sample data. Available in non-production environments only.</p>

            <ul class="types">
                @foreach ($types as $slug => $label)
                    <li>
                        <span class="name">{{ $label }}</span>
                        <span class="links">
                            <a class="primary" href="{{ url("/dev/mail-preview/{$slug}") }}">View</a>
                            @if ($slug === 'inactivity-reminder')
                                <a href="{{ url("/dev/mail-preview/{$slug}?days=7&variant=0") }}">7d v0</a>
                                <a href="{{ url("/dev/mail-preview/{$slug}?days=7&variant=1") }}">7d v1</a>
                                <a href="{{ url("/dev/mail-preview/{$slug}?days=14&variant=0") }}">14d v0</a>
                                <a href="{{ url("/dev/mail-preview/{$slug}?days=14&variant=1") }}">14d v1</a>
                                <a href="{{ url("/dev/mail-preview/{$slug}?days=30&variant=0") }}">30d v0</a>
                                <a href="{{ url("/dev/mail-preview/{$slug}?days=30&variant=1") }}">30d v1</a>
                            @elseif ($slug === 'insights')
                                <a href="{{ url("/dev/mail-preview/{$slug}?frequency=weekly") }}">Weekly</a>
                                <a href="{{ url("/dev/mail-preview/{$slug}?frequency=monthly") }}">Monthly</a>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="help">
                <div class="help-title">Query parameters</div>
                <div class="help-row">Inactivity: <code>?days=7|14|30&variant=0|1|2</code></div>
                <div class="help-row">Insights: <code>?frequency=weekly|monthly</code></div>
                <div class="help-row">Generic: <code>?subject=...&body=...</code></div>
                <div class="help-row">All types: <code>?first_name=...&last_name=...&email=...</code></div>
            </div>
        </div>
    </div>
</body>
</html>
