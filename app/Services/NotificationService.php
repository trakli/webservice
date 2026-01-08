<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Mail\GenericMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class NotificationService
{
    public const CONFIG_EMAIL_NOTIFICATIONS = 'notifications-email';

    public const CONFIG_PUSH_NOTIFICATIONS = 'notifications-push';

    public const CONFIG_INAPP_NOTIFICATIONS = 'notifications-inapp';

    public const CONFIG_REMINDER_NOTIFICATIONS = 'notifications-reminders';

    public const CONFIG_INSIGHTS_NOTIFICATIONS = 'notifications-insights';

    public const CONFIG_INACTIVITY_NOTIFICATIONS = 'notifications-inactivity';

    public function __construct(
        protected ?Messaging $messaging = null
    ) {}

    /**
     * Send a notification through all enabled channels.
     */
    public function send(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        array $data = [],
        ?Mailable $mailable = null,
        array $channels = ['inapp', 'push', 'email']
    ): array {
        $results = [
            'inapp' => null,
            'push' => null,
            'email' => null,
        ];

        if (! $this->isNotificationTypeEnabled($user, $type)) {
            Log::info('Notification type disabled by user preference', [
                'user_id' => $user->id,
                'type' => $type->value,
            ]);

            return $results;
        }

        if (in_array('inapp', $channels) && $this->isChannelEnabled($user, 'inapp')) {
            $results['inapp'] = $this->sendInApp($user, $type, $title, $body, $data);
        }

        if (in_array('push', $channels) && $this->isChannelEnabled($user, 'push')) {
            $results['push'] = $this->sendPush($user, $title, $body, $data);
        }

        if (in_array('email', $channels) && $this->isChannelEnabled($user, 'email')) {
            $results['email'] = $this->sendEmail($user, $title, $body, $mailable);
        }

        return $results;
    }

    /**
     * Send an in-app notification.
     */
    public function sendInApp(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        array $data = []
    ): ?Notification {
        try {
            return Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create in-app notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send a push notification via Firebase.
     */
    public function sendPush(
        User $user,
        string $title,
        string $body,
        array $data = []
    ): bool {
        if (! $this->messaging) {
            Log::debug('Firebase messaging not configured');

            return false;
        }

        try {
            $devices = $user->devices()->whereNotNull('token')->get();

            if ($devices->isEmpty()) {
                Log::debug('No devices with tokens found for user', ['user_id' => $user->id]);

                return false;
            }

            $successCount = 0;
            foreach ($devices as $device) {
                try {
                    $message = CloudMessage::withTarget('token', $device->token)
                        ->withNotification([
                            'title' => $title,
                            'body' => $body,
                        ])
                        ->withData(array_map('strval', $data));

                    $this->messaging->send($message);
                    $successCount++;
                } catch (\Throwable $e) {
                    Log::warning('Push notification failed for device', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $successCount > 0;
        } catch (\Throwable $e) {
            Log::error('Push notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send an email notification.
     */
    public function sendEmail(
        User $user,
        string $subject,
        string $body,
        ?Mailable $mailable = null,
        bool $queue = true
    ): bool {
        try {
            $mail = $mailable ?? new GenericMail($subject, $body);
            $mailBuilder = Mail::to($user->email);

            if ($queue) {
                $mailBuilder->queue($mail);
            } else {
                $mailBuilder->send($mail);
            }

            Log::info('Email notification sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Email notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if a specific channel is enabled for a user.
     */
    public function isChannelEnabled(User $user, string $channel): bool
    {
        $configKey = match ($channel) {
            'email' => self::CONFIG_EMAIL_NOTIFICATIONS,
            'push' => self::CONFIG_PUSH_NOTIFICATIONS,
            'inapp' => self::CONFIG_INAPP_NOTIFICATIONS,
            default => null,
        };

        if (! $configKey) {
            return false;
        }

        $value = $user->getConfigValue($configKey);

        return $value === null || $value === true || $value === 'true' || $value === '1';
    }

    /**
     * Check if a notification type is enabled for a user.
     */
    public function isNotificationTypeEnabled(User $user, NotificationType $type): bool
    {
        $configKey = match ($type) {
            NotificationType::REMINDER => self::CONFIG_REMINDER_NOTIFICATIONS,
            NotificationType::SYSTEM => self::CONFIG_INSIGHTS_NOTIFICATIONS,
            NotificationType::ALERT => self::CONFIG_INACTIVITY_NOTIFICATIONS,
            default => null,
        };

        if (! $configKey) {
            return true;
        }

        $value = $user->getConfigValue($configKey);

        return $value === null || $value === true || $value === 'true' || $value === '1';
    }

    /**
     * Update a user's notification channel preference.
     */
    public function setChannelPreference(User $user, string $channel, bool $enabled): void
    {
        $configKey = match ($channel) {
            'email' => self::CONFIG_EMAIL_NOTIFICATIONS,
            'push' => self::CONFIG_PUSH_NOTIFICATIONS,
            'inapp' => self::CONFIG_INAPP_NOTIFICATIONS,
            default => throw new \InvalidArgumentException("Invalid channel: {$channel}"),
        };

        $user->setConfigValue($configKey, $enabled ? 'true' : 'false', ConfigValueType::String);
    }

    /**
     * Update a user's notification type preference.
     */
    public function setTypePreference(User $user, string $type, bool $enabled): void
    {
        $configKey = match ($type) {
            'reminders' => self::CONFIG_REMINDER_NOTIFICATIONS,
            'insights' => self::CONFIG_INSIGHTS_NOTIFICATIONS,
            'inactivity' => self::CONFIG_INACTIVITY_NOTIFICATIONS,
            default => throw new \InvalidArgumentException("Invalid notification type: {$type}"),
        };

        $user->setConfigValue($configKey, $enabled ? 'true' : 'false', ConfigValueType::String);
    }

    /**
     * Get all notification preferences for a user.
     */
    public function getPreferences(User $user): array
    {
        return [
            'channels' => [
                'email' => $this->isChannelEnabled($user, 'email'),
                'push' => $this->isChannelEnabled($user, 'push'),
                'inapp' => $this->isChannelEnabled($user, 'inapp'),
            ],
            'types' => [
                'reminders' => $this->isNotificationTypeEnabled($user, NotificationType::REMINDER),
                'insights' => $this->isNotificationTypeEnabled($user, NotificationType::SYSTEM),
                'inactivity' => $this->isNotificationTypeEnabled($user, NotificationType::ALERT),
            ],
        ];
    }

    /**
     * Send an email notification to a specific email address.
     */
    public function sendEmailNotification(array $data): bool
    {
        try {
            $to = $data['to'] ?? null;
            $subject = $data['subject'] ?? 'Notification';
            $body = $data['body'] ?? '';

            if (! $to) {
                return false;
            }

            $mail = new GenericMail($subject, $body);
            Mail::to($to)->queue($mail);

            Log::info('Email notification sent', ['email' => $to]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Email notification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
