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
    }
}
