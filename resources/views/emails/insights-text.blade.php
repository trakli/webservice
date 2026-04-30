@php $periodKey = strtolower($periodLabel); @endphp
@if (! empty($user?->first_name))
{{ __('Hi :name,', ['name' => $user->first_name]) }}

@endif
{{ strtoupper(__("Your $periodKey insights")) }}
{{ $insights['period']['label'] ?? '' }}

{{ strtoupper(__('Summary')) }}
{{ __('Income') }}:   {{ number_format($insights['income'], 2) }}
{{ __('Expenses') }}: {{ number_format($insights['expenses'], 2) }}
{{ __('Net') }}:      {{ number_format($insights['net'], 2) }}

{{ strtoupper(__('Key metrics')) }}
{{ __('Savings rate') }}:               {{ $insights['savings_rate'] }}%
{{ __('Transactions') }}:               {{ $insights['transaction_count'] }}
{{ __('Expenses vs prior period') }}:   {{ $insights['expense_change_percent'] > 0 ? '+' : '' }}{{ $insights['expense_change_percent'] }}%

@if (! empty($insights['expenses_by_category']))
{{ strtoupper(__('Top spending categories')) }}
@foreach ($insights['expenses_by_category'] as $category => $amount)
- {{ $category }}: {{ number_format($amount, 2) }}
@endforeach

@endif
@if (! empty($insights['top_expense']))
{{ strtoupper(__('Largest transaction')) }}
{{ $insights['top_expense']['description'] ?: __('Untitled') }}
{{ number_format($insights['top_expense']['amount'], 2) }}@if (! empty($insights['top_expense']['category'])) ({{ $insights['top_expense']['category'] }})@endif

@endif
{{ __('View full report') }}:
{{ config('app.frontend_url', config('app.url')) }}/dashboard

---
{{ __('Manage preferences') }}: {{ config('app.frontend_url', config('app.url')) }}/settings
{{ __('Blog') }}: https://trakli.app/blog/
{{ __('Support') }}: support@trakli.app
(c) {{ date('Y') }} Trakli
