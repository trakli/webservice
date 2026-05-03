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

        $mail->assertSeeInHtml('weekly insights');
        $mail->assertSeeInHtml('5,000.00');
        $mail->assertSeeInHtml('3,200.00');
        $mail->assertSeeInHtml('1,800.00');
        $mail->assertSeeInHtml('Food &amp; Dining', false);
        $mail->assertSeeInHtml('Monthly Rent Payment');
        $mail->assertSeeInHtml('View full report');
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

        $this->assertEquals('Your monthly financial insights', $mail->envelope()->subject);
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
        $mail->assertSeeInHtml('14 days since your last transaction');
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

    public function test_user_preferred_locale_reflects_default_lang_config(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/configurations', [
            'key' => 'default-lang',
            'type' => 'string',
            'value' => 'fr',
        ])->assertStatus(201);

        $this->assertEquals('fr', $user->fresh()->preferredLocale());
    }

    public function test_inactivity_subject_translates_with_app_locale(): void
    {
        $user = User::factory()->create(['first_name' => 'Alex']);
        $tier = \App\Services\InactivityService::INACTIVITY_TIERS[7][0];
        $mail = new InactivityReminderMail($user, $tier, 7);

        app()->setLocale('fr');
        $subjectFr = $mail->envelope()->subject;
        app()->setLocale('en');
        $subjectEn = $mail->envelope()->subject;

        $this->assertSame('Your tracking went quiet', $subjectEn);
        $this->assertSame('Votre suivi s\'est calmé', $subjectFr);
    }

    public function test_insights_html_translates_static_labels_in_french(): void
    {
        $user = User::factory()->create();
        $insights = [
            'income' => 100, 'expenses' => 50, 'net' => 50,
            'savings_rate' => 50, 'transaction_count' => 1, 'expense_change_percent' => 0,
            'period' => ['label' => 'Test'], 'expenses_by_category' => [], 'top_expense' => null,
        ];
        $mail = new InsightsMail($user, $insights, 'Weekly');

        app()->setLocale('fr');
        $mail->assertSeeInHtml('Revenus');
        $mail->assertSeeInHtml('Dépenses', false);
        $mail->assertSeeInHtml('Voir le rapport complet');
    }
}
