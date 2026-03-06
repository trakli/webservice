<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\FindsUser;
use App\Mail\AccountDeletedMail;
use App\Mail\GenericMail;
use App\Mail\InactivityReminderMail;
use App\Mail\InsightsMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailCommand extends Command
{
    use FindsUser;

    protected $signature = 'mail:test
        {type : Mail type (account-deleted|inactivity|insights|generic)}
        {identifier : User email or ID to send the test mail to}';

    protected $description = 'Send a test email of a given type to a user';

    private const MAIL_TYPES = [
        'account-deleted',
        'inactivity',
        'insights',
        'generic',
    ];

    public function handle(): void
    {
        $type = $this->argument('type');

        if (! in_array($type, self::MAIL_TYPES)) {
            $this->error("Unknown mail type '{$type}'. Available: " . implode(', ', self::MAIL_TYPES));

            return;
        }

        $user = $this->findUser();
        if (! $user) {
            return;
        }

        $mailable = match ($type) {
            'account-deleted' => new AccountDeletedMail($user->first_name . ' ' . $user->last_name),
            'inactivity' => $this->buildInactivityMail($user),
            'insights' => $this->buildInsightsMail($user),
            'generic' => new GenericMail('Test Email from Trakli', "Hi {$user->first_name},\n\nThis is a test email."),
        };

        Mail::to($user->email)->send($mailable);
        $this->info("Sent '{$type}' test email to {$user->email}.");
    }

    private function buildInactivityMail(User $user): InactivityReminderMail
    {
        $tier = [
            'subject' => 'We miss you!',
            'message' => 'It has been a while since your last transaction.',
            'encouragement' => 'Just a few minutes of tracking can make a big difference!',
            'cta' => 'Log a quick transaction to get back on track.',
        ];

        return new InactivityReminderMail($user, $tier, 7);
    }

    private function buildInsightsMail(User $user): InsightsMail
    {
        $insights = [
            'total_income' => 150000,
            'total_expenses' => 95000,
            'net_savings' => 55000,
            'top_categories' => [
                ['name' => 'Food', 'amount' => 35000],
                ['name' => 'Transport', 'amount' => 25000],
                ['name' => 'Utilities', 'amount' => 20000],
            ],
        ];

        return new InsightsMail($user, $insights, 'Weekly');
    }
}
