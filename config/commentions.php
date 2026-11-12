<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */

    'models' => [
        'comment' => \App\Models\Comment::class,
        'subscription' => \Kirschbaum\Commentions\Models\Subscription::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Aquí desactivamos las notificaciones de menciones
    |
    */

    'notifications' => [
        'mentions' => [
            'enabled' => false,
            'channels' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | Desactivamos completamente las suscripciones
    |
    */

    'subscriptions' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'pagination' => [
        'per_page' => 10,
    ],

];