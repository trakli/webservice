{{ __('Keep it going') }}

{{ __('You have kept :activity going for :count :period in a row.', ['activity' => $activity, 'count' => $milestone, 'period' => $periodLabel]) }}
@if ($longest > $milestone)

{{ __('Your best run so far is :count.', ['count' => $longest]) }}
@endif

{{ config('app.frontend_url', config('app.url')) }}

{{ __('Thanks,') }}
{{ config('app.name') }}
