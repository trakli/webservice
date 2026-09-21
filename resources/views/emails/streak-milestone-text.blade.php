@if (! empty($streak->owner?->first_name))
{{ __('Hi :name,', ['name' => $streak->owner->first_name]) }}

@endif
{{ __(':count :period in a row', ['count' => $milestone, 'period' => $periodLabel]) }}

{{ __('You have kept :activity going for :count :period in a row. Money is easier to see when the record is complete.', ['activity' => $activity, 'count' => $milestone, 'period' => $periodLabel]) }}

@if ($longest > $milestone)
{{ __('Your best run is :count', ['count' => $longest]) }}
@else
{{ __('This is your best run yet') }}
@endif

{{ __('Add a transaction') }}:
{{ config('app.frontend_url', config('app.url')) }}/transactions

---
{{ __('Manage preferences') }}: {{ config('app.frontend_url', config('app.url')) }}/settings
{{ __('Support') }}: support@trakli.app
(c) {{ date('Y') }} Trakli
