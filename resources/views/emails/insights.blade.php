<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $periodLabel }} Financial Insights</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #eee; }
        .header h1 { margin: 0; color: #2563eb; font-size: 24px; }
        .period { color: #666; font-size: 14px; margin-top: 5px; }
        .summary { display: flex; justify-content: space-between; padding: 20px 0; border-bottom: 1px solid #eee; }
        .summary-item { text-align: center; flex: 1; }
        .summary-item .value { font-size: 24px; font-weight: bold; }
        .summary-item .label { font-size: 12px; color: #666; text-transform: uppercase; }
        .income { color: #16a34a; }
        .expense { color: #dc2626; }
        .net { color: #2563eb; }
        .section { padding: 20px 0; border-bottom: 1px solid #eee; }
        .section h2 { font-size: 16px; margin: 0 0 15px 0; color: #333; }
        .category-row { display: flex; justify-content: space-between; padding: 8px 0; }
        .category-name { color: #333; }
        .category-amount { font-weight: 500; }
        .highlight { background: #f8fafc; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .stat { display: flex; justify-content: space-between; padding: 5px 0; }
        .change-positive { color: #dc2626; }
        .change-negative { color: #16a34a; }
        .footer { text-align: center; padding: 20px 0; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $periodLabel }} Insights</h1>
        <div class="period">{{ $insights['period']['label'] }}</div>
    </div>

    <table width="100%" cellpadding="0" cellspacing="0" style="padding: 20px 0; border-bottom: 1px solid #eee;">
        <tr>
            <td align="center" width="33%">
                <div class="income" style="font-size: 24px; font-weight: bold;">{{ number_format($insights['income'], 2) }}</div>
                <div style="font-size: 12px; color: #666; text-transform: uppercase;">Income</div>
            </td>
            <td align="center" width="33%">
                <div class="expense" style="font-size: 24px; font-weight: bold;">{{ number_format($insights['expenses'], 2) }}</div>
                <div style="font-size: 12px; color: #666; text-transform: uppercase;">Expenses</div>
            </td>
            <td align="center" width="33%">
                <div class="net" style="font-size: 24px; font-weight: bold;">{{ number_format($insights['net'], 2) }}</div>
                <div style="font-size: 12px; color: #666; text-transform: uppercase;">Net</div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="highlight">
            <div class="stat">
                <span>Savings Rate</span>
                <strong>{{ $insights['savings_rate'] }}%</strong>
            </div>
            <div class="stat">
                <span>Transactions</span>
                <strong>{{ $insights['transaction_count'] }}</strong>
            </div>
            <div class="stat">
                <span>vs Previous Period</span>
                <strong class="{{ $insights['expense_change_percent'] > 0 ? 'change-positive' : 'change-negative' }}">
                    {{ $insights['expense_change_percent'] > 0 ? '+' : '' }}{{ $insights['expense_change_percent'] }}%
                </strong>
            </div>
        </div>
    </div>

    @if(count($insights['expenses_by_category']) > 0)
    <div class="section">
        <h2>Top Spending Categories</h2>
        @foreach($insights['expenses_by_category'] as $category => $amount)
        <div class="category-row">
            <span class="category-name">{{ $category }}</span>
            <span class="category-amount">{{ number_format($amount, 2) }}</span>
        </div>
        @endforeach
    </div>
    @endif

    @if($insights['top_expense'])
    <div class="section">
        <h2>Biggest Expense</h2>
        <div class="highlight">
            <strong>{{ $insights['top_expense']['description'] ?? 'N/A' }}</strong><br>
            <span style="color: #dc2626; font-size: 18px;">{{ number_format($insights['top_expense']['amount'], 2) }}</span>
            @if($insights['top_expense']['category'])
            <br><small style="color: #666;">{{ $insights['top_expense']['category'] }}</small>
            @endif
        </div>
    </div>
    @endif

    <div class="footer">
        <p>You're receiving this because you enabled {{ strtolower($periodLabel) }} insights.</p>
        <p>To change your preferences, visit your account settings.</p>
    </div>
</body>
</html>
