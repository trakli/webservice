<?php

namespace App\Listeners;

use App\Enums\ConfigValueType;
use App\Events\UserRegisteredEvent;
use App\Models\UserConfiguration;

class UserRegistered
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(UserRegisteredEvent $event)
    {
        $user = $event->user;
        // Create default user groups
        $defaultGroups = [
            'General',
            'Personal',
            'Family',
            'Friends',
            'Work',
        ];

        foreach ($defaultGroups as $group) {
            $user->groups()->create([
                'name' => $group,
                'description' => "Default group for $group",
            ]);
        }

        // set the default group
        UserConfiguration::setValue('default_group_id', $user->groups()->first()->id, $user->id, ConfigValueType::Integer);

        // create a default wallet
        $default_wallet = $user->wallets()->create([
            'name' => 'General',
            'type' => 'mobile',
        ]);

        UserConfiguration::setValue('default_wallet_id', $default_wallet->id, $user->id, ConfigValueType::Integer);

    }
}
