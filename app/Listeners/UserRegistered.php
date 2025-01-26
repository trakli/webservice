<?php

namespace App\Listeners;

use App\Events\UserRegisteredEvent;

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
        //Create default user groups
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
    }
}
