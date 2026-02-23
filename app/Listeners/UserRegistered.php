<?php

namespace App\Listeners;

use Illuminate\Support\Str;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;
use Whilesmart\UserAuthentication\Events\UserRegisteredEvent;

class UserRegistered
{
    private const SERVER_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(UserRegisteredEvent $event)
    {
        $user = $event->user;

        $this->createDefaultGroups($user);
        $this->createDefaultWallet($user);
    }

    private function createDefaultGroups($user): void
    {
        $defaultGroups = [
            ['key' => 'general', 'name' => __('General'), 'description' => __('Default group for General')],
            ['key' => 'personal', 'name' => __('Personal'), 'description' => __('Default group for Personal')],
            ['key' => 'family', 'name' => __('Family'), 'description' => __('Default group for Family')],
            ['key' => 'friends', 'name' => __('Friends'), 'description' => __('Default group for Friends')],
            ['key' => 'work', 'name' => __('Work'), 'description' => __('Default group for Work')],
        ];

        $generalGroup = null;

        foreach ($defaultGroups as $groupData) {
            $key = $groupData['key'];
            unset($groupData['key']);
            $group = $user->groups()->create($groupData);

            if ($key === 'general') {
                $generalGroup = $group;
            }
        }

        if ($generalGroup) {
            $clientId = self::SERVER_UUID . ':' . Str::uuid()->toString();
            $generalGroup->setClientGeneratedId($clientId, $user);

            $user->setConfigValue(
                'default-group',
                $clientId,
                ConfigValueType::String
            );
        }
    }

    private function createDefaultWallet($user): void
    {
        $wallet = $user->wallets()->create([
            'name' => __('Main Wallet'),
            'description' => __('Your primary wallet'),
            'type' => 'cash',
            'currency' => 'USD',
            'balance' => 0,
        ]);

        $clientId = self::SERVER_UUID . ':' . Str::uuid()->toString();
        $wallet->setClientGeneratedId($clientId, $user);

        $user->setConfigValue(
            'default-wallet',
            $clientId,
            ConfigValueType::String
        );
    }
}
