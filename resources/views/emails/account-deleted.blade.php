<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Deleted</title>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f8faf9; }
        .container { background: #ffffff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #e6f2ec; }
        .content { font-size: 16px; color: #374151; }
        .content p { margin-bottom: 16px; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e6f2ec; color: #6b7280; font-size: 12px; }
        .footer a { color: #047844; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="color: #374151; margin: 0;">Account Deleted</h2>
        </div>

        <div class="content">
            <p>Hi {{ $userName }},</p>

            <p>Your Trakli account and all associated data have been permanently deleted as requested.</p>

            <p>This includes all your wallets, transactions, categories, groups, and other data.</p>

            <p>If you did not request this deletion, please contact us immediately at <a href="mailto:support@trakli.app">support@trakli.app</a>.</p>

            <p>We're sorry to see you go. If you ever want to come back, you're always welcome to create a new account.</p>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} Trakli. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
