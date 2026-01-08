<?php

namespace Tests\Feature;

use App\Mail\GenericMail;
use App\Mail\InactivityReminderMail;
use App\Mail\InsightsMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_mail_renders_correctly(): void
    {
        $subject = 'Test Notification';
        $body = 'This is a test notification body.';

        $mail = new GenericMail($subject, $body);

        $mail->assertSeeInHtml($body);
        $mail->assertSeeInHtml('Trakli');
    }

    public function test_generic_mail_has_correct_subject(): void
    {
        $subject = 'Test Subject Line';
        $body = 'Test body content';

        $mail = new GenericMail($subject, $body);

        $this->assertEquals($subject, $mail->envelope()->subject);
    }

    public function test_insights_mail_renders_correctly(): void
    {
        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User']);

        $insights = [
            'income' => 5000.00,
            'expenses' => 3200.00,
            'net' => 1800.00,
            'savings_rate' => 36,
            'transaction_count' => 47,
            'expense_change_percent' => -12,
            'period' => [
                'label' => 'Dec 30 - Jan 5, 2025',
            ],
            'expenses_by_category' => [
                'Food & Dining' => 850.00,
                'Transportation' => 420.00,
            ],
            'top_expense' => [
                'description' => 'Monthly Rent Payment',
                'amount' => 1200.00,
                'category' => 'Housing',
            ],
        ];

        $mail = new InsightsMail($user, $insights, 'Weekly');

        $mail->assertSeeInHtml('Weekly Insights');
        $mail->assertSeeInHtml('5,000.00');
        $mail->assertSeeInHtml('3,200.00');
        $mail->assertSeeInHtml('1,800.00');
        $mail->assertSeeInHtml('Food & Dining');
        $mail->assertSeeInHtml('Monthly Rent Payment');
        $mail->assertSeeInHtml('View Full Report');
    }

    public function test_insights_mail_has_correct_subject(): void
    {
        $user = User::factory()->create();

        $insights = [
            'income' => 1000.00,
            'expenses' => 500.00,
            'net' => 500.00,
            'savings_rate' => 50,
            'transaction_count' => 10,
            'expense_change_percent' => 0,
            'period' => ['label' => 'Test Period'],
            'expenses_by_category' => [],
            'top_expense' => null,
        ];

        $mail = new InsightsMail($user, $insights, 'Monthly');

        $this->assertEquals('Your Monthly Financial Insights', $mail->envelope()->subject);
    }

    public function test_inactivity_reminder_mail_renders_correctly(): void
    {
        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User']);

        $tier = [
            'subject' => 'We miss you! Time to track your finances',
            'message' => 'Tracking your expenses regularly helps you stay on top of your financial goals.',
            'encouragement' => 'Even logging just one transaction today can help build the habit.',
            'cta' => 'It only takes a minute to get back on track.',
        ];

        $mail = new InactivityReminderMail($user, $tier, 14);

        $mail->assertSeeInHtml('We miss you!');
        $mail->assertSeeInHtml('14 days since last transaction');
        $mail->assertSeeInHtml('Tracking your expenses regularly');
        $mail->assertSeeInHtml('Even logging just one transaction');
        $mail->assertSeeInHtml('Trakli');
    }

    public function test_inactivity_reminder_mail_has_correct_subject(): void
    {
        $user = User::factory()->create();

        $tier = [
            'subject' => 'Custom Subject Line',
            'message' => 'Test message',
            'encouragement' => 'Test encouragement',
            'cta' => 'Test CTA',
        ];

        $mail = new InactivityReminderMail($user, $tier, 7);

        $this->assertEquals('Custom Subject Line', $mail->envelope()->subject);
    }
}
