<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1a1a1a; }
        h1 { font-size: 16pt; margin: 0 0 4mm; }
        h2 { font-size: 11pt; margin: 6mm 0 2mm; padding-bottom: 1mm; border-bottom: 1px solid #d4d4d4; }
        .meta { color: #555; font-size: 8pt; margin-bottom: 2mm; }
        .meta span { margin-right: 6mm; }
        .notice { background: #fdf3e2; border-left: 3px solid #d9a441; padding: 2mm 3mm; margin-bottom: 3mm; font-size: 8pt; }
        .note { color: #555; font-size: 8pt; margin-bottom: 2mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 1.4mm 2mm; border-bottom: 1px solid #e6e6e6; }
        th { background: #f4f4f4; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        tr { page-break-inside: avoid; }
        .summary td { border-bottom: none; padding: 0.8mm 0; }
        .summary .label { color: #555; width: 45%; }
        .summary .value { text-align: right; font-weight: bold; }
        .empty { color: #777; font-style: italic; }
    </style>
</head>
<body>
<h1>{{ $title }}</h1>

@if (! empty($meta))
    <div class="meta">
        @foreach ($meta as $label => $value)
            <span>{{ $label }}: {{ $value }}</span>
        @endforeach
    </div>
@endif

@foreach ($notices as $notice)
    <div class="notice">{{ $notice }}</div>
@endforeach

@foreach ($sections as $section)
    <h2>{{ $section['heading'] }}</h2>

    @if ($section['note'])
        <div class="note">{{ $section['note'] }}</div>
    @endif

    @if (! empty($section['summary']))
        <table class="summary">
            @foreach ($section['summary'] as $label => $value)
                <tr>
                    <td class="label">{{ $label }}</td>
                    <td class="value">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($section['columns']))
        <table>
            <thead>
            <tr>
                @foreach ($section['columns'] as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($section['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($section['columns']) }}">{{ __('Nothing to show for this selection.') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    @endif
@endforeach
</body>
</html>
