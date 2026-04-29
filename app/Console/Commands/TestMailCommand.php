<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\FindsUser;
use App\Mail\AccountDeletedMail;
use App\Mail\GenericMail;
use App\Mail\InactivityReminderMail;
use App\Mail\InsightsMail;
use App\Models\User;
use App\Services\InactivityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

        Mail::to($user)->send($mailable);
        $this->info("Sent '{$type}' test email to {$user->email}.");
    }

    private function buildInactivityMail(User $user): InactivityReminderMail
    {
        $variants = InactivityService::INACTIVITY_TIERS[7];
        $variant = $variants[array_rand($variants)];

        return new InactivityReminderMail($user, $variant, 7);
    }

    private function buildInsightsMail(User $user): InsightsMail
    {
        $start = Carbon::now()->subWeek()->startOfWeek();
        $end = Carbon::now()->subWeek()->endOfWeek();

        $insights = [
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => $start->format('M j') . ' - ' . $end->format('M j, Y'),
            ],
            'frequency' => 'weekly',
            'income' => 150000,
            'expenses' => 95000,
            'net' => 55000,
            'savings_rate' => 36.7,
            'transaction_count' => 24,
            'expenses_by_category' => [
                'Food' => 35000,
                'Transport' => 25000,
                'Utilities' => 20000,
            ],
            'top_expense' => [
                'description' => 'Monthly rent',
                'amount' => 18000,
                'category' => 'Housing',
            ],
            'expense_change_percent' => -4.2,
        ];

        return new InsightsMail($user, $insights, 'Weekly');
    }
}
