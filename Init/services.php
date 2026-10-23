<?php

namespace Okay\Modules\Sviat\ReviewRequests;

use Okay\Core\Config;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Notify;
use Okay\Core\OkayContainer\Reference\ParameterReference as PR;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Extenders\FrontExtender;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestBackfill;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestCron;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestMailer;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestPreferences;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestQueue;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use Psr\Log\LoggerInterface;

return [
    ReviewRequestSettings::class => [
        'class' => ReviewRequestSettings::class,
        'arguments' => [
            new SR(Settings::class),
        ],
    ],
    ReviewRequestRules::class => [
        'class' => ReviewRequestRules::class,
        'arguments' => [
            new SR(ReviewRequestSettings::class),
        ],
    ],
    ReviewRequestQueue::class => [
        'class' => ReviewRequestQueue::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(QueryFactory::class),
            new SR(ReviewRequestSettings::class),
            new SR(ReviewRequestRules::class),
        ],
    ],
    ReviewRequestMailer::class => [
        'class' => ReviewRequestMailer::class,
        'arguments' => [
            new SR(Notify::class),
            new SR(Design::class),
            new SR(Settings::class),
            new SR(Languages::class),
            new SR(FrontTranslations::class),
            new SR(Config::class),
            new SR(Image::class),
            new SR(ReviewRequestSettings::class),
            new SR(EntityFactory::class),
            new PR('root_dir'),
        ],
    ],
    ReviewRequestCron::class => [
        'class' => ReviewRequestCron::class,
        'arguments' => [
            new SR(ReviewRequestQueue::class),
            new SR(ReviewRequestRules::class),
            new SR(ReviewRequestMailer::class),
            new SR(ReviewRequestSettings::class),
            new SR(LoggerInterface::class),
        ],
    ],
    ReviewRequestBackfill::class => [
        'class' => ReviewRequestBackfill::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(QueryFactory::class),
            new SR(ReviewRequestSettings::class),
            new SR(ReviewRequestRules::class),
        ],
    ],
    ReviewRequestReviews::class => [
        'class' => ReviewRequestReviews::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(QueryFactory::class),
            new SR(Settings::class),
        ],
    ],
    ReviewRequestPreferences::class => [
        'class' => ReviewRequestPreferences::class,
        'arguments' => [
            new SR(EntityFactory::class),
        ],
    ],
    FrontExtender::class => [
        'class' => FrontExtender::class,
        'arguments' => [
            new SR(ReviewRequestReviews::class),
            new SR(ReviewRequestSettings::class),
        ],
    ],
];
