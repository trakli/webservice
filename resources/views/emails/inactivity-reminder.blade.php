@extends('emails.layouts.base', [
    'title' => $tier['subject'],
    'preheader' => $tier['message'],
])

@section('content')
    @if (! empty($user?->first_name))
        <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#1f2937;">
            {{ __('Hi :name,', ['name' => $user->first_name]) }}
        </p>
    @endif

    <p style="margin:0 0 8px; font-size:12px; color:#6b7280; text-transform:uppercase; letter-spacing:0.5px; font-weight:500;">
        {{ __(':days days since your last transaction', ['days' => $daysInactive]) }}
    </p>
    <h1 class="h1 text-heading" style="margin:0 0 16px; font-size:24px; line-height:1.3; color:#0f3a23; font-weight:700;">
        {{ __($tier['subject']) }}
    </h1>

    <p style="margin:0 0 24px; font-size:15px; line-height:1.65; color:#1f2937;">
        {{ __($tier['message']) }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
        <tr>
            <td class="panel border-soft" style="background-color:#f7faf8; border:1px solid #e5e9e7; border-left:3px solid #047844; border-radius:6px; padding:20px;">
                <p style="margin:0 0 8px; font-size:12px; color:#0f3a23; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
                    {{ __('Catch up faster with the importer') }}
                </p>
                <p style="margin:0 0 16px; font-size:14px; line-height:1.65; color:#1f2937;">
                    {{ __($tier['encouragement']) }}
                </p>
                @include('emails.partials.button', [
                    'href' => config('app.frontend_url', config('app.url')) . '/imports',
                    'label' => __($tier['cta']),
                    'variant' => 'primary',
                ])
            </td>
        </tr>
    </table>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#6b7280; text-align:center;">
        {{ __('Prefer to enter them yourself?') }}
        <a href="{{ config('app.frontend_url', config('app.url')) }}/transactions" style="color:#047844; text-decoration:none;">{{ __('Log a transaction manually') }}</a>.
    </p>
@endsection

@section('footerNote')
    {{ __("You're receiving this because inactivity reminders are on.") }}
@endsection
