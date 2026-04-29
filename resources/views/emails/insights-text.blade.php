@if (! empty($user?->first_name))
Hi {{ $user->first_name }},

@endif
YOUR {{ strtoupper($periodLabel) }} INSIGHTS
{{ $insights['period']['label'] ?? '' }}

SUMMARY
Income:   {{ number_format($insights['income'], 2) }}
Expenses: {{ number_format($insights['expenses'], 2) }}
Net:      {{ number_format($insights['net'], 2) }}

KEY METRICS
Savings rate:               {{ $insights['savings_rate'] }}%
Transactions:               {{ $insights['transaction_count'] }}
Expenses vs prior period:   {{ $insights['expense_change_percent'] > 0 ? '+' : '' }}{{ $insights['expense_change_percent'] }}%

@if (! empty($insights['expenses_by_category']))
TOP SPENDING CATEGORIES
@foreach ($insights['expenses_by_category'] as $category => $amount)
- {{ $category }}: {{ number_format($amount, 2) }}
@endforeach

@endif
@if (! empty($insights['top_expense']))
LARGEST TRANSACTION
{{ $insights['top_expense']['description'] ?: 'Untitled' }}
{{ number_format($insights['top_expense']['amount'], 2) }}@if (! empty($insights['top_expense']['category'])) ({{ $insights['top_expense']['category'] }})@endif

@endif
View full report:
{{ config('app.frontend_url', config('app.url')) }}/dashboard

---
Manage email preferences: {{ config('app.frontend_url', config('app.url')) }}/settings
Contact support: support@trakli.app
(c) {{ date('Y') }} Trakli
