<?php

namespace App\Services;

use App\Mail\GenericMail;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Send an email notification.
     *
     * @param  array  $emailData  Email data (to, subject, body).
     * @param  bool  $queue  Whether to queue the email.
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function sendEmailNotification(array $emailData = [], ?Mailable $mailable = null, bool $queue = true): bool
    {
        if (is_null($mailable)) {
            if (empty($emailData)) {
                throw new \InvalidArgumentException('Either a mailable or emailData must be provided.');
            }
            $recipientEmail = $emailData['to'];
            $mailable = new GenericMail($emailData['subject'], $emailData['body']);
        }

        try {
            $queue ? Mail::to($recipientEmail)->queue($mailable) : Mail::to($recipientEmail)->send($mailable);

            return true;
        } catch (\Exception $e) {
            Log::error("Email to {$recipientEmail} failed: ".$e->getMessage());

            return false;
        }
    }
}
