<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private per-user channel for FleetAlert realtime push. Laravel's notification
| broadcaster targets "App.Models.User.{id}" by default; a user may only listen on
| their own channel. Active once BROADCAST_CONNECTION is pointed at Reverb/Pusher and
| the frontend subscribes via Laravel Echo (until then the bell stays live via polling).
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
