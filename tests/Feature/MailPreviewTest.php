<?php

namespace Tests\Feature;

use App\Mail\AccountDeletedMail;
use App\Mail\GenericMail;
use App\Mail\InactivityReminderMail;
use App\Mail\InsightsMail;
use App\Services\InactivityService;
use Tests\TestCase;

class MailPreviewTest extends TestCase
{
    public function test_index_lists_every_mail_type()
    {
        $response = $this->get('/dev/mail-preview');

        $response->assertOk();
        $response->assertSee('Inactivity reminder');
        $response->assertSee('Periodic insights');
        $response->assertSee('Account deleted');
        $response->assertSee('Generic notification');
    }

    public function test_inactivity_preview_renders_html_and_uses_selected_variant()
    {
        $variant = InactivityService::INACTIVITY_TIERS[14][1];

        $response = $this->get('/dev/mail-preview/inactivity-reminder?days=14&variant=1');

        $response->assertOk();
        $response->assertSee($variant['subject'], false);
        $response->assertSee('14 days since your last transaction', false);
        $response->assertSee('/imports', false);
    }

    public function test_inactivity_preview_falls_back_to_lowest_tier_when_days_is_zero()
    {
        $response = $this->get('/dev/mail-preview/inactivity-reminder?days=0&variant=0');

        $response->assertOk();
        $response->assertSee(InactivityService::INACTIVITY_TIERS[7][0]['subject'], false);
    }

    public function test_inactivity_preview_clamps_invalid_variant_to_first()
    {
        $variant = InactivityService::INACTIVITY_TIERS[7][0];

        $response = $this->get('/dev/mail-preview/inactivity-reminder?days=7&variant=99');

        $response->assertOk();
        $response->assertSee($variant['subject'], false);
    }

    public function test_insights_preview_renders_with_weekly_default()
    {
        $response = $this->get('/dev/mail-preview/insights');

        $response->assertOk();
        $response->assertSee('weekly insights', false);
        $response->assertSee('Savings rate', false);
    }

    public function test_insights_preview_supports_monthly_frequency()
    {
        $response = $this->get('/dev/mail-preview/insights?frequency=monthly');

        $response->assertOk();
        $response->assertSee('monthly insights', false);
    }

    public function test_account_deleted_preview_uses_query_name()
    {
        $response = $this->get('/dev/mail-preview/account-deleted?first_name=Sam&last_name=Rivers');

        $response->assertOk();
        $response->assertSee('Hi Sam Rivers', false);
    }

    public function test_generic_preview_uses_query_subject_and_body()
    {
        $response = $this->get('/dev/mail-preview/generic?subject=Hello+world&body=This+is+a+body+line');

        $response->assertOk();
        $response->assertSee('Hello world', false);
        $response->assertSee('This is a body line', false);
    }

    public function test_unknown_type_returns_404()
    {
        $response = $this->get('/dev/mail-preview/does-not-exist');

        $response->assertNotFound();
    }

    public function test_each_inactivity_variant_provides_full_copy()
    {
        foreach (InactivityService::INACTIVITY_TIERS as $tierDays => $variants) {
            foreach ($variants as $index => $variant) {
                $message = "Tier {$tierDays} variant {$index}";
                $this->assertNotEmpty($variant['subject'], "{$message} missing subject");
                $this->assertNotEmpty($variant['message'], "{$message} missing message");
                $this->assertNotEmpty($variant['encouragement'], "{$message} missing encouragement");
                $this->assertNotEmpty($variant['cta'], "{$message} missing cta");
            }
        }
    }

    public function test_mailable_classes_use_expected_views()
    {
        $this->assertTrue(class_exists(InactivityReminderMail::class));
        $this->assertTrue(class_exists(InsightsMail::class));
        $this->assertTrue(class_exists(AccountDeletedMail::class));
        $this->assertTrue(class_exists(GenericMail::class));
    }
}
