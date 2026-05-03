@php
    $periodKey = strtolower($periodLabel);
@endphp
@extends('emails.layouts.base', [
    'title' => __("Your $periodKey insights"),
    'preheader' => __("Your $periodKey financial summary is ready."),
])

@section('content')
    @if (! empty($user?->first_name))
        <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#1f2937;">
            {{ __('Hi :name,', ['name' => $user->first_name]) }}
        </p>
    @endif

    <p style="margin:0 0 6px; font-size:12px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; font-weight:500;">
        {{ $insights['period']['label'] ?? '' }}
    </p>
    <h1 class="h1 text-heading" style="margin:0 0 24px; font-size:24px; line-height:1.3; color:#0f3a23; font-weight:700;">
        {{ __("Your $periodKey insights") }}
    </h1>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 16px;">
        <tr>
            <td class="panel border-soft" style="background-color:#fafbfa; border:1px solid #e5e9e7; border-radius:6px; padding:18px 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="stack">
                    <tr>
                        <td valign="top" width="33.33%" style="padding-right:14px; border-right:1px solid #e5e9e7;">
                            <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; font-weight:500;">{{ __('Income') }}</div>
                            <div style="font-size:20px; font-weight:600; color:#047844; line-height:1.2;">{{ number_format($insights['income'], 2) }}</div>
                        </td>
                        <td valign="top" width="33.33%" style="padding:0 14px; border-right:1px solid #e5e9e7;">
                            <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; font-weight:500;">{{ __('Expenses') }}</div>
                            <div style="font-size:20px; font-weight:600; color:#b91c1c; line-height:1.2;">{{ number_format($insights['expenses'], 2) }}</div>
                        </td>
                        <td valign="top" width="33.33%" style="padding-left:14px;">
                            <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; font-weight:500;">{{ __('Net') }}</div>
                            <div style="font-size:22px; font-weight:700; color:#0f3a23; line-height:1.2;">{{ number_format($insights['net'], 2) }}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @php $changeColor = ($insights['expense_change_percent'] ?? 0) > 0 ? '#b91c1c' : '#047844'; @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
        <tr>
            <td class="panel border-soft" style="background-color:#fafbfa; border:1px solid #e5e9e7; border-radius:6px; padding:18px 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="stack">
                    <tr>
                        <td valign="top" width="33.33%" style="padding-right:14px; border-right:1px solid #e5e9e7;">
                            <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; font-weight:500;">{{ __('Savings rate') }}</div>
                            <div style="font-size:18px; font-weight:600; color:#047844; line-height:1.2;">{{ $insights['savings_rate'] }}%</div>
                        </td>
                        <td valign="top" width="33.33%" style="padding:0 14px; border-right:1px solid #e5e9e7;">
                            <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; font-weight:500;">{{ __('Transactions') }}</div>
                            <div style="font-size:18px; font-weight:600; color:#1f2937; line-height:1.2;">{{ $insights['transaction_count'] }}</div>
                        </td>
                        <td valign="top" width="33.33%" style="padding-left:14px;">
                            <div style="font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px; font-weight:500;">{{ __('vs prior period') }}</div>
                            <div style="font-size:18px; font-weight:600; color: {{ $changeColor }}; line-height:1.2;">{{ $insights['expense_change_percent'] > 0 ? '+' : '' }}{{ $insights['expense_change_percent'] }}%</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (! empty($insights['expenses_by_category']))
        <p style="margin:0 0 10px; font-size:12px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
            {{ __('Top spending categories') }}
        </p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
            <tr>
                <td class="panel border-soft" style="background-color:#fafbfa; border:1px solid #e5e9e7; border-radius:6px; padding:8px 20px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        @foreach ($insights['expenses_by_category'] as $category => $amount)
                            <tr>
                                <td class="row-divider" style="padding:11px 0; font-size:14px; color:#1f2937; @if (! $loop->first) border-top:1px solid #e5e9e7; @endif">{{ $category }}</td>
                                <td align="right" class="row-divider" style="padding:11px 0; font-size:14px; font-weight:600; color:#1f2937; @if (! $loop->first) border-top:1px solid #e5e9e7; @endif">{{ number_format($amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif

    @if (! empty($insights['top_expense']))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 28px;">
            <tr>
                <td class="panel border-soft" style="background-color:#fafbfa; border:1px solid #e5e9e7; border-left:3px solid #FF9500; border-radius:6px; padding:20px;">
                    <p style="margin:0 0 6px; font-size:12px; color:#92400e; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">
                        {{ __('Largest transaction') }}
                    </p>
                    <p style="margin:0 0 4px; font-size:16px; font-weight:600; color:#1f2937;">{{ $insights['top_expense']['description'] ?: __('Untitled') }}</p>
                    <p style="margin:0 0 4px; font-size:20px; font-weight:700; color:#b45309;">{{ number_format($insights['top_expense']['amount'], 2) }}</p>
                    @if (! empty($insights['top_expense']['category']))
                        <p style="margin:0; font-size:13px; color:#6b7280;">{{ $insights['top_expense']['category'] }}</p>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 0 auto;">
        <tr>
            <td>
                @include('emails.partials.button', [
                    'href' => config('app.frontend_url', config('app.url')) . '/dashboard',
                    'label' => __('View full report'),
                    'variant' => 'primary',
                ])
            </td>
        </tr>
    </table>
@endsection

@section('footerNote')
    {{ __("You're receiving this because $periodKey insights are on.") }}
@endsection
