@if (! empty($user?->first_name))
{{ __('Hi :name,', ['name' => $user->first_name]) }}

@endif
{{ __($tier['subject']) }}

{{ __(':days days since your last transaction', ['days' => $daysInactive]) }}.

{{ __($tier['message']) }}

{{ __('Catch up faster with the importer') }}
{{ __($tier['encouragement']) }}

{{ __($tier['cta']) }}:
{{ config('app.frontend_url', config('app.url')) }}/imports

{{ __('Prefer to enter them yourself?') }} {{ __('Log a transaction manually') }}:
{{ config('app.frontend_url', config('app.url')) }}/transactions

---
{{ __('Manage preferences') }}: {{ config('app.frontend_url', config('app.url')) }}/settings
{{ __('Blog') }}: https://trakli.app/blog/
{{ __('Support') }}: support@trakli.app
(c) {{ date('Y') }} Trakli
