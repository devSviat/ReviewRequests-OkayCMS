<?php

use Okay\Modules\Sviat\ReviewRequests\Controllers\ReviewRequestController;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestToken;

// Токен лише в першому запиті: open() і unsubscribe() кладуть запит у сесію й
// редиректять на адресу без токена, щоб він не потрапив в історію, Referer і аналітику.
return [
    'Sviat_ReviewRequests_unsubscribe' => [
        'slug' => '/review-request/unsubscribe/{$token}',
        'patterns' => [
            '{$token}' => '(' . ReviewRequestToken::PATTERN . ')',
        ],
        'params' => [
            'controller' => ReviewRequestController::class,
            'method' => 'unsubscribe',
        ],
    ],
    'Sviat_ReviewRequests_unsubscribe_form' => [
        'slug' => '/review-request/unsubscribe',
        'params' => [
            'controller' => ReviewRequestController::class,
            'method' => 'unsubscribeForm',
        ],
    ],
    'Sviat_ReviewRequests_preferences' => [
        'slug' => '/review-request/preferences',
        'params' => [
            'controller' => ReviewRequestController::class,
            'method' => 'preferences',
        ],
    ],
    'Sviat_ReviewRequests_open' => [
        'slug' => '/review-request/{$token}',
        'patterns' => [
            '{$token}' => '(' . ReviewRequestToken::PATTERN . ')',
        ],
        'params' => [
            'controller' => ReviewRequestController::class,
            'method' => 'open',
        ],
    ],
    'Sviat_ReviewRequests_form' => [
        'slug' => '/review-request',
        'params' => [
            'controller' => ReviewRequestController::class,
            'method' => 'form',
        ],
    ],
];
