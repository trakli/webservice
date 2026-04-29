@extends('emails.layouts.base', [
    'title' => __('Your Trakli account has been deleted'),
    'preheader' => __('Confirmation that your account and all associated data have been removed.'),
])

@section('content')
    <h1 class="h1 text-heading" style="margin:0 0 18px; font-size:22px; line-height:1.3; color:#0f3a23; font-weight:700;">
        {{ __('Account deleted') }}
    </h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.65;">
        {{ __('Hi :name,', ['name' => $userName]) }}
    </p>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.65;">
        {{ __('Your Trakli account and all associated data have been permanently deleted. This includes your wallets, transactions, categories, groups, files, and configuration.') }}
    </p>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.65;">
        {!! __("If you didn't request this, please contact us right away at :emailLink. After this point the data is no longer recoverable.", ['emailLink' => '<a href="mailto:support@trakli.app" style="color:#047844; text-decoration:none;">support@trakli.app</a>']) !!}
    </p>

    <p style="margin:0; font-size:15px; line-height:1.65;">
        {{ __("If you ever want to come back, you're always welcome to start fresh.") }}
    </p>
@endsection

@section('footerNote')
    {{ __('This is a one-time confirmation message.') }}
@endsection
