<?php

namespace App\Listeners;

use Whilesmart\ModelConfiguration\Enums\ConfigValueType;
use Whilesmart\UserAuthentication\Events\UserRegisteredEvent;

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

        $defaultGroups = [
            'General',
            'Personal',
            'Family',
            'Friends',
            'Work',
        ];

        $generalGroup = null;

        foreach ($defaultGroups as $groupName) {
            $group = $user->groups()->create([
                'name' => $groupName,
                'description' => "Default group for $groupName",
            ]);

            if ($groupName === 'General') {
                $generalGroup = $group;
            }
        }

        if ($generalGroup) {
            $user->setConfigValue(
                'default-group',
                (string) $generalGroup->id,
                ConfigValueType::String
            );
        }
    }
}
