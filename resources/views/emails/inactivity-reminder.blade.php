<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tier['subject'] }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5; }
        .container { background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0; color: #2563eb; font-size: 28px; }
        .days-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; margin-top: 15px; }
        .message { font-size: 18px; color: #333; margin-bottom: 25px; text-align: center; }
        .encouragement { background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); padding: 25px; border-radius: 10px; margin: 25px 0; border-left: 4px solid #2563eb; }
        .encouragement p { margin: 0; color: #1e40af; font-size: 15px; line-height: 1.7; }
        .cta-section { text-align: center; margin: 35px 0; }
        .cta-button { display: inline-block; background: #2563eb; color: #fff; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px; }
        .cta-text { color: #666; font-size: 14px; margin-top: 15px; }
        .tips { background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 30px; }
        .tips h3 { margin: 0 0 15px 0; font-size: 14px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .tips ul { margin: 0; padding-left: 20px; color: #475569; }
        .tips li { margin-bottom: 8px; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px; }
        .footer a { color: #2563eb; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $tier['subject'] }}</h1>
            <div class="days-badge">{{ $daysInactive }} days since last transaction</div>
        </div>

        <p class="message">{{ $tier['message'] }}</p>

        <div class="encouragement">
            <p>{{ $tier['encouragement'] }}</p>
        </div>

        <div class="cta-section">
            <a href="{{ config('app.frontend_url', config('app.url')) }}" class="cta-button">Open Trakli</a>
            <p class="cta-text">{{ $tier['cta'] }}</p>
        </div>

        <div class="tips">
            <h3>Quick tips to get back on track</h3>
            <ul>
                <li>Start with your biggest expenses - rent, bills, groceries</li>
                <li>Check your bank statement and add the last few transactions</li>
                <li>Set a daily reminder for just 2 minutes of tracking</li>
                <li>Don't aim for perfection - any tracking is better than none</li>
            </ul>
        </div>

        <div class="footer">
            <p>You're receiving this because you signed up for Trakli.</p>
            <p>Want to stop these reminders? <a href="{{ config('app.frontend_url', config('app.url')) }}/settings">Update your preferences</a></p>
        </div>
    </div>
</body>
</html>
